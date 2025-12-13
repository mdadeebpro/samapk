<?php
// === New Function: Image Optimization ===
function optimizeImage($source_path, $original_filename, $max_width = 800, $max_height = 800, $quality = 75) {
    // Check if GD library is installed
    if (!extension_loaded('gd') || !function_exists('gd_info')) {
        // Fallback: just move the original file if GD is not available
        $destination_path = 'uploads/' . $original_filename;
        if (move_uploaded_file($source_path, $destination_path)) {
            return $original_filename;
        }
        return false;
    }

    $info = getimagesize($source_path);
    if ($info === false) {
        return false; // Not a valid image file
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

    // Get original dimensions
    $width = imagesx($image);
    $height = imagesy($image);
    $ratio = $width / $height;

    // Calculate new dimensions while maintaining aspect ratio
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

    // Create a new true color image
    $new_image = imagecreatetruecolor($new_width, $new_height);

    // Handle transparency for PNG and GIF
    if ($mime == 'image/png' || $mime == 'image/gif') {
        imagealphablending($new_image, false);
        imagesavealpha($new_image, true);
        $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
        imagefilledrectangle($new_image, 0, 0, $new_width, $new_height, $transparent);
    }

    // Resize the image
    imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

    // --- New: Save as WebP ---
    // Create the new filename with .webp extension
    $new_filename = pathinfo($original_filename, PATHINFO_FILENAME) . '.webp';
    $destination_path = 'uploads/' . $new_filename;

    // Save the image as WebP
    imagewebp($new_image, $destination_path, $quality);

    // Free up memory
    imagedestroy($image);
    imagedestroy($new_image);

    // Return the new filename
    return $new_filename;
}

function validateImageUpload($file) {
    // 1. Check file size
    $maxFileSize = 5 * 1024 * 1024; // 5 MB
    if ($file['size'] > $maxFileSize) {
        return "خطأ: حجم الصورة يتجاوز الحد المسموح به (5 ميجا).";
    }

    // 2. Check file extension
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExtension, $allowedExtensions)) {
        return "خطأ: امتداد الصورة غير مسموح به.";
    }

    return true;
}
