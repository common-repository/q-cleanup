<?php


/**/
$qlean_parts = '';
if(isset($_POST['q-clean-unuseful'])) {
	$qlean_parts .= DelUnusedFilesFromDB();
}

if(isset($_POST['q-clean-leftover'])) {
	$qlean_parts .= DelUnusedFiles();
}

# Function for cleaning up the post revisions
function DelUnusedFilesFromDB() {
	global $wpdb;

	$files = UnusedFilesFromDB();
	$message = '';

	$base_dir = wp_upload_dir();
	$base_dir = $base_dir['basedir'];

	foreach($files as $file){
		$file = $file["file"];
		$del = false;
		if( file_exists($file) ) {
			$del = unlink($file);
		}else{
			$del = TRUE;
		}

		if($del == TRUE){
			$file = site_url() . strstr ( $file, '/wp-content');
			$postID = $wpdb->get_row("SELECT ID FROM $wpdb->posts WHERE guid = '" . $file . "' AND post_type = 'attachment'", ARRAY_A);

			$delMetaID = $wpdb->query("
				DELETE FROM $wpdb->postmeta
				WHERE post_id = '" . $postID["ID"] . "'
				"
			);
			if($delMetaID){
				$delPost = $wpdb->query("
					DELETE FROM $wpdb->posts
					WHERE guid = '" . $file . "'
					AND post_type = 'attachment'
				");
				if($delPost){
					$message .= $file.' is deleted.<br />';
				}
			}
		}else {
			$message .= $file." isn't deleted.<br />";
		}
	}
	
	return $message;
}

# Function for cleaning up the post revisions
function DelUnusedFiles() {
	$allDBFiles = AllFilesFromDB();
	$allFiles = AllFilesInDir();

	$files = getDifference( $allDBFiles, $allFiles);
	$message = '';

	$base_dir = wp_upload_dir();
	$base_dir = $base_dir['basedir'];

	foreach($files as $file){
		$del = unlink($file);
		if($del == TRUE){
			$message .= $file.' is deleted.<br />';
		}else {
			$message .= $file." isn't deleted.<br />";
		}
	}

	return $message;
}



/**---------------------------------------------------------------------------------------------------- Get all files from upload dir **/
function AllFilesFromDB() {
	global $wpdb;

	$sql = "
	SELECT $wpdb->posts.* 
	FROM $wpdb->posts
	WHERE $wpdb->posts.post_type = 'attachment'
	";

	$query = $wpdb->get_results($sql, OBJECT);

	$base_dir = wp_upload_dir();
	$base_dir = $base_dir['basedir'];

	$files = queryToFiles($query, $base_dir);

	return $files;
}



/**---------------------------------------------------------------------------------------------------- Get all used files from upload dir **/
function AllUsedFilesFromDB() {
	global $wpdb;

	$sql = "
	SELECT $wpdb->posts.* 
	FROM $wpdb->posts
	WHERE $wpdb->posts.post_type = 'attachment'
	AND $wpdb->posts.post_parent != '0'
	";

	$query = $wpdb->get_results($sql, OBJECT);

	$base_dir = wp_upload_dir();
	$base_dir = $base_dir['basedir'];

	$files = queryToFiles($query, $base_dir);

	return $files;
}



/**---------------------------------------------------------------------------------------------------- Get unused attachment files from db **/
function UnusedFilesFromDB() {
	global $wpdb;
	
	$sql = "
	SELECT $wpdb->posts.* 
	FROM $wpdb->posts
	WHERE $wpdb->posts.post_type = 'attachment'
	AND $wpdb->posts.post_parent = '0'
	";

	$query = $wpdb->get_results($sql, OBJECT);
	
	$base_dir = wp_upload_dir();
	$base_dir = $base_dir['basedir'];

	$files = queryToFiles($query, $base_dir);

	return $files;
}



/**---------------------------------------------------------------------------------------------------- Get all files from upload dir **/
function AllFilesInDir(){
	$base_dir = wp_upload_dir();
	$base_dir = $base_dir['basedir'];

	$files = array();

	foreach (ListFiles($base_dir) as $key=>$file){
		$files[] = $file;
	}
	return $files;
}

function ListFiles($dir) {
	if($dh = opendir($dir)) {

		$files = array();
		$inner_files = array();

		while($file = readdir($dh)) {
			if($file != "." && $file != ".." && $file[0] != '.') {
				if(is_dir($dir . "/" . $file)) {
					$inner_files = ListFiles($dir . "/" . $file);
					if(is_array($inner_files)) $files = array_merge($files, $inner_files); 
				} else {
					array_push($files, $dir . "/" . $file);
				}
			}
		}

		closedir($dh);
		return $files;
	}
}



/**---------------------------------------------------------------------------------------------------- Extract and return all files uri and ids from query **/
function queryToFiles($query, $base_dir){
	$files = array();

	foreach($query as $row){
		$meta = wp_get_attachment_metadata($row->ID);
		if( !empty( $meta ) ){
			$file_name = $meta['file'];
			$sub_dir = '/'.substr($file_name, 0, strripos($file_name, '/')+1);
		}else{
			$meta = get_post_meta($row->ID, '_wp_attached_file');
			$file_name = $meta[0];
			$sub_dir = false;
		}

		$files[] = array(
			'file' => $base_dir.'/'.$file_name,
			//'id' => $row->ID
		);

		if($sub_dir){
			foreach( $meta['sizes'] as $size => $name){
				$files[] = array(
					'file' => $base_dir.$sub_dir.$name['file'],
					//'id' => false
				);
			}
		}
	}
	return $files;
}



/**---------------------------------------------------------------------------------------------------- Extract and return all files uri and ids from query **/
function getDifference($db_arr, $arr){
	$res = array();

	foreach($arr as $file){
		if(!in_multiarray($file, $db_arr)){
			$res[] = $file;
		}
	}

	return $res;
}

function in_multiarray($elem, $array){
	$len = sizeof($array) - 1;
	$current = 0;
	while($current <= $len){
		if($array[$current] == $elem)
			return true;
		else
			if(is_array($array[$current]))
				if(in_array( $elem, $array[$current] ) )
					return true;

		$current++;
	}			 
	return false;
}


/**---------------------------------------------------------------------------------------------------- Calculate space usage for files in list **/
function FilesSize($files){
	$totalusedspace = 0;
	$size = 0;

	foreach( $files as $file ){
		if(is_array($file)){
			$file = $file['file'];
		}

		if( file_exists($file) ){
			$size = filesize( $file );
		}

		if( $size ){
			$totalusedspace += $size;
		}
	}

	return $totalusedspace;
}

function FileSizeUnit($totalusedspace){
	$size = $totalusedspace / 1024;
	if($size < 1024){
		$size = number_format($size, 2);
		$size .= ' KB';
	}else{
		if($size / 1024 < 1024){
			$size = number_format($size / 1024, 2);
			$size .= ' MB';
		}else if ($size / 1024 / 1024 < 1024){
			$size = number_format($size / 1024 / 1024, 2);
			$size .= ' GB';
		}
	}
	echo $size;
}


/**---------------------------------------------------------------------------------------------------- Calculate percentage **/
function Percentage($total, $divide) {
	$divide = ($divide / $total) * 100;
	$divide = round($divide, 2);
	
	return $divide;
}


/**---------------------------------------------------------------------------------------------------- Sanitalize collections **/
function array_unique_multidimensional($arr) {
	$serialized = array_map('serialize', $arr);
	$unique = array_unique($serialized);
	return array_intersect_key($arr, $unique);
}



/**---------------------------------------------------------------------------------------------------- Admin page **/
$allFiles = AllFilesInDir();
$allFilesSize = FilesSize( $allFiles );

$allDBFiles = AllFilesFromDB();
$allDBFilesSize = FilesSize( $allDBFiles );
$allDBFilesProc = Percentage( $allFilesSize, $allDBFilesSize );

$allUsedDBFiles = array_unique_multidimensional( AllUsedFilesFromDB() );
$allUsedDBFilesSize = FilesSize( $allUsedDBFiles );
$allUsedDBFilesProc = Percentage( $allFilesSize, $allUsedDBFilesSize );

$unusedFiles = getDifference( $allDBFiles, $allFiles );
$unusedFilesSize = FilesSize( $unusedFiles );
$unusedFilesProc = Percentage( $allFilesSize, $unusedFilesSize );

$unusedDBFiles = UnusedFilesFromDB();
$unusedDBFilesSize = FilesSize( $unusedDBFiles );
$unusedDBFilesProc = Percentage( $allFilesSize, $unusedDBFilesSize );
?>
<style type="text/css">
#icon-options-general {
	background: url(<?php echo plugin_dir_url( __FILE__ ); ?>icon.png) 50% 50% no-repeat;
}
</style>
<div class="wrap">
<?php screen_icon(); ?>
<h2><?php _e('Q Cleanup'); ?></h2>

<?php if($qlean_parts <> '') { ?>
<div id="message" class="updated fade"><?php echo $qlean_parts; ?></div>
<?php } ?>

<h3>Upload dir chart</h3>
<div align="center">
<img src="https://chart.googleapis.com/chart?cht=p3&chs=600x200&chd=t:<?php echo $unusedDBFilesProc; ?>,<?php echo $allUsedDBFilesProc; ?>,<?php echo $unusedFilesProc; ?>&chl=Unused Files|Used Files|Leftovers&chco=298CBA">
</div>

<h3>Report</h3>
<form action="#" method="post" id="qlean-form">
<table class="widefat">
	<thead>
		<th width="5%">Cleanup?</th>
		<th width="50%">Description</th>
		<th width="15%">Number of files</th>
		<th width="15%">Size</th>
		<th width="15%">Percentage of total</th>
	</thead>
	<tr>
		<td></td>
		<td>Upload Dir content</td>
		<td><?php echo count($allFiles); ?></td>
		<td><?php FileSizeUnit($allFilesSize); ?></td>
		<td>100%</td>
	</tr>
	<tr>
		<td></td>
		<td>Used Files</td>
		<td><?php echo count($allUsedDBFiles); ?></td>
		<td><?php FileSizeUnit($allUsedDBFilesSize); ?></td>
		<td><?php echo $allUsedDBFilesProc; ?>%</td>
	</tr>
	<tr>
		<td align="center"><input type="checkbox" name="q-clean-unuseful" id="q-clean-unuseful"></td>
		<td><label for="q-clean-unuseful" title="Uploaded Files that are not used in posts">Unused Files</label></td>
		<td><?php echo count($unusedDBFiles); ?></td>
		<td><?php FileSizeUnit($unusedDBFilesSize); ?></td>
		<td><?php echo $unusedDBFilesProc; ?>%</td>
	</tr>
	<tr>
		<td align="center"><input type="checkbox" name="q-clean-leftover" id="q-clean-leftover"></td>
		<td><label for="q-clean-leftover" title="Files in upload dir for which WordPress doesn't have a record">Leftovers</label></td>
		<td><?php echo count($unusedFiles); ?></td>
		<td><?php FileSizeUnit($unusedFilesSize); ?></td>
		<td><?php echo $unusedFilesProc; ?>%</td>
	</tr>
</table>
<p>Make sure you have a backup of you Wordpress DataBase and upload dir before Cleanup!!!</p>
<input type="submit" name="submit" value="Cleanup !" style="padding: 0.5em 2em;" class="button-primary" <?php if( count($unusedDBFiles) == 0 && count($unusedFiles) == 0 ): ?>disabled="disabled"<?php endif; ?>>
</form>
</div>