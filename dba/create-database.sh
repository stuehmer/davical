#!/bin/sh
#
# Build the DAViCal database
#

DBNAME="${1:-davical}"
ADMINPW="${2}"

DBADIR="`dirname \"$0\"`"

testawldir() {
  [ -f "${1}/dba/awl-tables.sql" ]
}

#
# Attempt to locate the AWL directory
AWLDIR="${DBADIR}/../../awl"
if ! testawldir "${AWLDIR}"; then
  AWLDIR="/usr/share/awl"
  if ! testawldir "${AWLDIR}"; then
    AWLDIR="/usr/local/share/awl"
    if ! testawldir "${AWLDIR}"; then
      echo "Unable to find AWL libraries"
      exit 1
    fi
  fi
fi

export AWL_DBAUSER=davical_dba
export AWL_APPUSER=davical_app

# Get the major version for PostgreSQL
export DBVERSION="`psql -qAt template1 -c "SELECT version();" | cut -f2 -d' ' | cut -f1-2 -d'.'`"

db_users() {
  psql -qAt template1 -c "SELECT usename FROM pg_user;";
}

create_db_user() {
  if ! db_users | grep "^${1}$" >/dev/null ; then
    createuser --no-superuser --no-createdb --no-createrole "${1}"
  fi
}

create_plpgsql_language() {
  if ! psql -qAt template1 -c "SELECT lanname FROM pg_language;" | grep "^plpgsql$" >/dev/null; then
    createlang plpgsql "${DBNAME}"
  fi
}

create_db_user "${AWL_DBAUSER}"
create_db_user "${AWL_APPUSER}"

# FIXME: Need to check that the database was actually created.
if ! createdb --encoding UTF8 "${DBNAME}" --template template0 --owner "${AWL_DBAUSER}"; then
  echo "Unable to create database"
  exit 1
fi

create_plpgsql_language

#
# Load the AWL base tables and schema management tables
psql -q -f "${AWLDIR}/dba/awl-tables.sql" "${DBNAME}" 2>&1 | egrep -v "(^CREATE |^GRANT|^BEGIN|^COMMIT| NOTICE: )"
psql -q -f "${AWLDIR}/dba/schema-management.sql" "${DBNAME}" 2>&1 | egrep -v "(^CREATE |^GRANT|^BEGIN|^COMMIT| NOTICE: )"

#
# Load the DAViCal tables
psql -q -f "${DBADIR}/davical.sql" "${DBNAME}" 2>&1 | egrep -v "(^CREATE |^GRANT|^BEGIN|^COMMIT| NOTICE: )"

# psql -q -f "${DBADIR}/caldav_functions.sql" "${DBNAME}"

#
# The supported locales are in a separate file to make them easier to upgrade
#psql -q -f "${DBADIR}/supported_locales.sql" "${DBNAME}"

#
# Set permissions for the application DB user on the database
${DBADIR}/update-rscds-database --dbname "${DBNAME}" --appuser "${AWL_APPUSER}" --nopatch --revoke "general"

#
# Load the required base data
psql -q -f "${DBADIR}/base-data.sql" "${DBNAME}"

#
# We can override the admin password generation for regression testing predictability
if [ "${ADMINPW}" = "" ] ; then
  #
  # Generate a random administrative password.  If pwgen is available we'll use that,
  # otherwise try and hack something up using a few standard utilities
  ADMINPW="`pwgen -Bcny 2>/dev/null | tr \"\\\\\'\" '^='`"
fi

if [ "$ADMINPW" = "" ] ; then
  # OK.  They didn't supply one, and pwgen didn't work, so we hack something
  # together from /dev/random ...
  ADMINPW="`dd if=/dev/urandom bs=512 count=1 2>/dev/null | tr -c -d "a-km-zA-HJ-NP-Y0-9" | cut -c2-9`"
fi

if [ "$ADMINPW" = "" ] ; then
  # Right.  We're getting desperate now.  We'll have to use a default password
  # and hope that they change it to something more sensible.
  ADMINPW="please change this password"
fi

psql -q -c "UPDATE usr SET password = '**${ADMINPW}' WHERE user_no = 1;" "${DBNAME}"

echo "The password for the 'admin' user has been set to '${ADMINPW}'"
