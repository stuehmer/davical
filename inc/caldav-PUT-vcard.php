<?php
/**
* CalDAV Server - handle PUT method on VCARD content-types
*
* @package   davical
* @subpackage   caldav
* @author    Andrew McMillan <andrew@morphoss.com>
* @copyright Morphoss Ltd
* @license   http://gnu.org/copyleft/gpl.html GNU GPL v2 or later
*/
dbg_error_log("PUT", "method handler");

require_once('DAVResource.php');

if ( ! ini_get('open_basedir') && (isset($c->dbg['ALL']) || (isset($c->dbg['put']) && $c->dbg['put'])) ) {
  $fh = fopen('/tmp/PUT.txt','w');
  if ( $fh ) {
    fwrite($fh,$request->raw_post);
    fclose($fh);
  }
}

$lock_opener = $request->FailIfLocked();

$dest = new DAVResource($request->path);

$container = $dest->FetchParentContainer();
if ( ! $dest->Exists() ) {
  if ( $container->IsPrincipal() ) {
    $request->DoResponse(403,translate('A DAViCal principal collection may only contain collections'));
  }
  if ( ! $container->Exists() ) {
    $request->DoResponse( 409, translate('Destination collection does not exist') );
  }
  $container->NeedPrivilege('DAV::bind');
}
else {
  if ( $dest->IsCollection() ) {
    if ( ! isset($c->readonly_webdav_collections) || $c->readonly_webdav_collections ) {
      $request->DoResponse(403,translate('You may not PUT to a collection URL'));
    }
    $request->DoResponse(403,translate('PUT on a collection is only allowed for text/calendar content against a calendar collection'));
  }
  $dest->NeedPrivilege('DAV::write-content');
}

if ( isset($request->etag_none_match) && $request->etag_none_match != '*' && $dest->Exists() ) {
  $request->DoResponse(412);
}

if ( isset($request->etag_if_match) && $request->etag_if_match != $dest->unique_tag() ) {
  $request->DoResponse(412);
}

$collection_id = $container->GetProperty('collection_id');

$qry = new AwlQuery();
$qry->Begin();

$params = array(
    ':user_no' => $dest->GetProperty('user_no'),
    ':dav_name' => $dest->bound_from(),
    ':etag' => md5($request->raw_post),
    ':dav_data' => $request->raw_post,
    ':session_user' => $session->user_no
);
if ( $dest->Exists() ) {
  $sql = 'UPDATE caldav_data SET caldav_data=:dav_data, dav_etag=:etag, logged_user=:session_user,
          modified=current_timestamp, user_no=:user_no WHERE dav_name=:dav_name';
  $response_code = 200;
  $qry->QDo( $sql, $params );

  $qry->QDo("SELECT dav_id FROM caldav_data WHERE dav_name = :dav_name ", array(':dav_name' => $params[':dav_name']) );
}
else {
  $sql = 'INSERT INTO caldav_data ( user_no, dav_name, dav_etag, caldav_data, logged_user, created, modified, collection_id )
          VALUES( :user_no, :dav_name, :etag, :dav_data, :session_user, current_timestamp, current_timestamp, :collection_id )';
  $params[':collection_id'] = $collection_id;
  $response_code = 201;
  $qry->QDo( $sql, $params );

  $qry->QDo("SELECT currval('dav_id_seq') AS dav_id" );
}
$row = $qry->Fetch();

require_once('vcard.php');

$vcard = new vCard( $request->raw_post );
$vcard->Write( $row->dav_id, $dest->Exists() );

$qry->QDo("SELECT write_sync_change( $collection_id, $response_code, :dav_name)", array(':dav_name' => $dest->bound_from() ) );

if ( !$qry->QDo('COMMIT') ) rollback(500);

$request->DoResponse( $response_code );
