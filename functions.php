<?php
// === New Function: Image Optimization ===
function optimizeImage($source_path, $destination_path, $max_width = 800, $max_height = 800, $quality = 75) {
    if (!extension_loaded('gd') || !function_exists('gd_info')) {
        // GD library is not installed
        return move_uploaded_file($source_path, $destination_path);
    }

    $info = getimagesize($source_path);
    if ($info === false) {
        return false; // Not an image
    }

    $mime = $info['mime'];
    switch ($mime) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($source_path);
            break;
        case 'image/png':
            $image = imagecreatefrompng($source_path);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($source_path);
            break;
        default:
            return false; // Unsupported image type
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $ratio = $width / $height;

    if ($width > $max_width || $height > $max_height) {
        if ($max_width / $max_height > $ratio) {
            $new_width = $max_height * $ratio;
            $new_height = $max_height;
        } else {
            $new_height = $max_width / $ratio;
            $new_width = $max_width;
        }
    } else {
        $new_width = $width;
        $new_height = $height;
    }

    $new_image = imagecreatetruecolor($new_width, $new_height);

    // Handle transparency for PNG
    if ($mime == 'image/png') {
        imagealphablending($new_image, false);
        imagesavealpha($new_image, true);
        $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
        imagefilledrectangle($new_image, 0, 0, $new_width, $new_height, $transparent);
    }

    imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

    switch ($mime) {
        case 'image/jpeg':
            imagejpeg($new_image, $destination_path, $quality);
            break;
        case 'image/png':
            imagepng($new_image, $destination_path, 6); // Compression level 0-9
            break;
        case 'image/gif':
            imagegif($new_image, $destination_path);
            break;
    }

    imagedestroy($image);
    imagedestroy($new_image);
    return true;
}
