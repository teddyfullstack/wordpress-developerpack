<?php
/*
 *  @author nguyenhongphat0 <nguyenhongphat28121998@gmail.com>
 *  @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0
 */

function response( $data ) {
	echo json_encode( $data );
	wp_die();
}

add_action( 'wp_ajax_developerpack_test', 'developerpack_test' );
function developerpack_test() {
	$value = $_POST['value'];
	response( $value );
}

function listFiles( $path ) {
	$project = realpath( $path );
	$directory = new RecursiveDirectoryIterator( $project );
	$files = new RecursiveIteratorIterator( $directory, RecursiveIteratorIterator::LEAVES_ONLY );
	return $files;
}

function archive( $regex, $output, $maxsize, $timeout ) {
	// Extend excecute limit
	if ( isset( $timeout ) ) {
		set_time_limit( $timeout );
	}

	// Get files in directory
	$project = realpath( '..' );
	$files = $this->listFiles( $project );

	// Initialize archive object
	$zip = new ZipArchive();
	$zip->open( $output, ZipArchive::CREATE | ZipArchive::OVERWRITE );

	foreach ( $files as $name => $file )
	{
		// Skip directories ( they would be added automatically )
		$ok = ( preg_match( $regex, $name ) ) && ( !$file->isDir() ) && ( $file->getSize() < $maxsize );
		if ( $ok )
		{
			// Get real and relative path for current file
			$filePath = $file->getRealPath();
			$relativePath = substr( $filePath, strlen( $project ) + 1 );

			// Add current file to archive
			$zip->addFile( $filePath, $relativePath );
		}
	}

	// Zip archive will be created only after closing object
	$zip->close();
}

function implodeOptions( $options ) {
	function escape( $path )
	{
		$project = realpath( '..' );
		if ( substr( $path, 0, 1 ) === "/" ) {
			$path = $project.$path;
		}
		$path = str_replace( '.', '\.', $path );
		$path = str_replace( '/', '\/', $path );
		return( $path );
	}
	$options = array_map( "escape", $options );
	$regex = implode( '|', $options );
	return $regex;
}

function includeFiles( $includes ) {
	$regex = $this->implodeOptions( $includes );
	$regex = '/^.*( '.$regex.' ).*$/i';
	return $regex;
}

function excludeFiles( $excludes ) {
	$regex = $this->implodeOptions( $excludes );
	$regex = '/^( ( ?!'.$regex.' ). )*$/i';
	return $regex;
}

add_action( 'wp_ajax_developerpack_zip', 'developerpack_zip' );
function developerpack_zip()
{
	$files = $_POST['files'];
	$timeout = $_POST['timeout'];
	if ( isset( $_POST['maxsize'] ) ) {
		$maxsize = $_POST['maxsize'];
	} else {
		$maxsize = 1000000;
	}
	$empty = empty( $files );
	if ( $empty ) {
		response( 'Not enough parameters' );
	}
	foreach ( $files as $file ) {
		if ( $file === '' ) {
			response( 'Empty rules are not allowed' );
		}
	}
	$rules = $_POST['rule'];
	switch ( $rules ) {
	case 'include':
		$regex = $this->includeFiles( $files );
		break;

	case 'exclude':
		$regex = $this->excludeFiles( $files );
		break;

	default:
		response( 'Invalid rule' );
		break;
	}
	$output = 'zip/'.$_POST['output'];
	$this->archive( $regex, $output, $maxsize, $timeout );
	response( $_POST['output'] );
}

function humanFileSize( $size, $unit="" ) {
	if( ( !$unit && $size >= 1<<30 ) || $unit == "GB" )
		return number_format( $size/( 1<<30 ),2 )." GB";
	if( ( !$unit && $size >= 1<<20 ) || $unit == "MB" )
		return number_format( $size/( 1<<20 ),2 )." MB";
	if( ( !$unit && $size >= 1<<10 ) || $unit == "KB" )
		return number_format( $size/( 1<<10 ),2 )." KB";
	return number_format( $size )." bytes";
}

add_action( 'wp_ajax_developerpack_zipped', 'developerpack_zipped' );
function developerpack_zipped() {
	$files = array_diff( scandir( realpath( 'zip' ) ), array( '.', '..', '.keep' ) );
	$res = array();
	foreach ( $files as $file ) {
		$res[] = array(
			'name' => $file,
			'size' => $this->humanFileSize( filesize( 'zip/'.$file ) )
		);
	}
	response( $res );
}

add_action( 'wp_ajax_developerpack_analize', 'developerpack_analize' );
function developerpack_analize() {
	$start = microtime( true );
	$project = realpath( '..' );
	$files = $this->listFiles( $project );
	$size = $d = 0;
	foreach ( $files as $name => $file ) {
		$size += $file->getSize();
		$d++;
	}
	response( array(
		'total' => $d,
		'size' => $this->humanFileSize( $size ),
		'execution_time' => ( microtime( true ) - $start ).'s'
	) );
}

add_action( 'wp_ajax_developerpack_open', 'developerpack_open' );
function developerpack_open() {
	$project = realpath( '..' );
	$filename = $_POST['file'];
	$file = $project.'/'.$filename;
	$res = array(
		'status' => 404,
		'message' => 'List directory success'
	);
	if ( $filename !== '' && is_file( $file ) ) {
		$file = $project.'/'.$_POST['file'];
		$res['content'] = file_get_contents( $file );
		$res['status'] = 200;
		$res['message'] = 'OK';
	}
	if ( !is_dir( $file ) ) {
		$file = dirname( $file );
		if ( $res['status'] != 200 ) {
			$res['message'] = 'File or directory not found';
		}
	} else {
		$res['status'] = 204;
	}
	$res['pwd'] = $file;
	$ls = scandir( $file );
	$res['ls'] = $ls;
	response( $res );
}

add_action( 'wp_ajax_developerpack_save', 'developerpack_save' );
function developerpack_save() {
	$project = realpath( '..' );
	$filename = $_POST['file'];
	$content = stripslashes( $_POST['content'] );
	$file = $project.'/'.$filename;
	if ( $filename !== '' && is_file( $file ) ) {
		file_put_contents( $file, $content );
		$res = array(
			'status' => 200,
			'message' => 'File saved successfully!'
		);
	} else if ( $filename !== '' && !is_dir( $file ) ) {
		file_put_contents( $file, $content );
		$res = array(
			'status' => 200,
			'message' => 'File created successfully!'
		);
	} else {
		$res = array(
			'status' => 404,
			'message' => 'Error saving file!'
		);
	}
	response( $res );
}

add_action( 'wp_ajax_developerpack_delete', 'developerpack_delete' );
function developerpack_delete() {
	$project = realpath( '..' );
	$filename = $_POST['file'];
	$file = $project.'/'.$filename;
	if ( $filename !== '' && is_file( $file ) ) {
		unlink( $file );
		$res = array(
			'status' => 200,
			'message' => 'File deleted successfully!'
		);
	} else {
		$res = array(
			'status' => 404,
			'message' => 'Nothing has been deleted!'
		);
	}
	response( $res );
}
