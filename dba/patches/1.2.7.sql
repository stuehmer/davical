
-- This database update refines the constraint on usr in order to try and be
-- able to actually DELETE FROM usr WHERE user_no = x; and have the database
-- do the right thing...

BEGIN;
SELECT check_db_revision(1,2,6);

CREATE TABLE sync_tokens (
  sync_token SERIAL PRIMARY KEY,
  collection_id INT8 REFERENCES collection(collection_id) ON DELETE CASCADE ON UPDATE CASCADE,
  modification_time TIMESTAMP WITH TIME ZONE DEFAULT current_timestamp
);

CREATE TABLE sync_changes (
  sync_time TIMESTAMP WITH TIME ZONE DEFAULT current_timestamp,
  collection_id INT8 REFERENCES collection(collection_id) ON DELETE CASCADE ON UPDATE CASCADE,
  sync_status INT,
  dav_id INT8 REFERENCES calendar_item(dav_id) ON DELETE SET NULL ON UPDATE RESTRICT,
  dav_name TEXT
);

SELECT new_db_revision(1,2,7, 'Juli' );

COMMIT;
ROLLBACK;


CREATE or REPLACE FUNCTION write_sync_change( INT8, INT, TEXT ) RETURNS BOOLEAN AS $$
DECLARE
  in_collection_id ALIAS FOR $1;
  in_status ALIAS FOR $2;
  in_dav_name ALIAS FOR $3;
  tmp_int INT8;
BEGIN
  SELECT 1 INTO tmp_int FROM sync_tokens
           WHERE collection_id = in_collection_id
           LIMIT 1;
  IF NOT FOUND THEN
    RETURN FALSE;
  END IF;
  SELECT dav_id INTO tmp_int FROM calendar_item WHERE dav_name = in_dav_name;
  INSERT INTO sync_changes ( collection_id, sync_status, dav_id, dav_name)
                     VALUES( in_collection_id, in_status, tmp_int, in_dav_name);
  RETURN TRUE;
END
$$ LANGUAGE 'PlPgSQL' VOLATILE STRICT;


CREATE or REPLACE FUNCTION new_sync_token( INT8, INT8 ) RETURNS INT8 AS $$
DECLARE
  in_old_sync_token ALIAS FOR $1;
  in_collection_id ALIAS FOR $2;
  tmp_int INT8;
BEGIN
  IF in_old_sync_token > 0 THEN
    SELECT 1 INTO tmp_int FROM sync_changes
            WHERE collection_id = in_collection_id
              AND sync_time > (SELECT modification_time FROM sync_tokens WHERE sync_token = in_old_sync_token)
            LIMIT 1;
    IF NOT FOUND THEN
      RETURN in_old_sync_token;
    END IF;
  END IF;
  SELECT nextval('sync_tokens_sync_token_seq') INTO tmp_int;
  INSERT INTO sync_tokens(collection_id, sync_token) VALUES( in_collection_id, tmp_int );
  RETURN tmp_int;
END
$$ LANGUAGE 'PlPgSQL' STRICT;
