<?php
// Secure image uploads for every photo field: avatars, plants, story entries, listings (CORE-17).
// Checks: a real upload, at most 5 MB, a real JPG or PNG by its content (finfo, not the file name), sane dimensions.
// Saves under a random name in public/uploads/<folder>/, resized to 1600 px on the long side when GD is available.
// public/uploads/.htaccess stops Apache from ever running a file in that folder.

require_once __DIR__ . '/helpers.php';   // config()

const UPLOAD_TYPES   = ['image/jpeg' => 'jpg', 'image/png' => 'png'];   // what finfo must say the content is
const UPLOAD_FOLDERS = ['avatars', 'plants', 'stories', 'listings', 'scans'];   // scans: photos sent to the ML service
const UPLOAD_MAX_PIXELS = 40000000;   // 40 megapixels: refuse "image bombs" that unpack to gigabytes in memory

/**
 * Save the photo sent in file field $field into public/uploads/$folder/.
 * Returns ['path' => 'plants/3f9c….jpg'] (the value to store in the database), ['path' => null] when no file was
 * chosen, or ['error' => 'message for the form'].
 */
function save_upload(string $field, string $folder): array
{
    if (!in_array($folder, UPLOAD_FOLDERS, true)) {
        throw new InvalidArgumentException('Unknown upload folder: ' . $folder);
    }
    $file = $_FILES[$field] ?? null;

    // A hand-made request can send photo[]=… (an array of files) where one file belongs: treat it as no file
    if (!is_array($file) || !isset($file['error']) || is_array($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['path' => null];
    }

    $max_bytes = (int) config('max_upload_mb') * 1024 * 1024;
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > $max_bytes) {
        return ['error' => 'That photo is larger than ' . config('max_upload_mb') . ' MB. Choose a smaller one.'];
    }
    // is_uploaded_file(): the path really came from this request's upload, not from a crafted value
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return ['error' => 'The photo did not arrive. Please try again.'];
    }

    // The type is read from the file's own bytes. The browser's type and the file name can both be faked:
    // "plant.jpg.php" or a PHP script renamed to .jpg would pass a name check, but not this one.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(UPLOAD_TYPES[$mime])) {
        return ['error' => 'Use a JPG or PNG photo.'];
    }
    $size = @getimagesize($file['tmp_name']);   // @: a broken image is reported below, not as a PHP warning
    if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > UPLOAD_MAX_PIXELS) {
        return ['error' => 'That photo could not be read. Try another one.'];
    }

    // A random name: nobody can guess other people's photo addresses or overwrite a file by choosing its name
    $name      = bin2hex(random_bytes(16)) . '.' . UPLOAD_TYPES[$mime];
    $directory = rtrim(config('upload_dir'), '/\\') . '/' . $folder;
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        error_log('PlantFolio: cannot create upload folder ' . $directory);
        return ['error' => 'The photo could not be saved. Please try again later.'];
    }
    $target = $directory . '/' . $name;

    // With GD: re-draw the picture at most 1600 px on the long side. Re-drawing also drops anything hidden in the
    // file (camera location data, or code tucked after the image). Without GD the checked original is kept.
    if (extension_loaded('gd')) {
        $saved = resize_image($file['tmp_name'], $target, $mime, $size[0], $size[1], (int) config('max_image_px'));
    } else {
        $saved = move_uploaded_file($file['tmp_name'], $target);
    }
    if (!$saved) {
        error_log('PlantFolio: could not save upload to ' . $target);
        return ['error' => 'The photo could not be saved. Please try again later.'];
    }

    return ['path' => $folder . '/' . $name];
}

/** Draw the image again, at most $max_px on its long side, as the same type. Returns false if GD fails. */
function resize_image(string $source, string $target, string $mime, int $width, int $height, int $max_px): bool
{
    $image = $mime === 'image/png' ? @imagecreatefrompng($source) : @imagecreatefromjpeg($source);
    if (!$image) {
        return false;
    }
    // A phone held upright stores the picture sideways and adds a note (EXIF "Orientation") telling viewers to turn it.
    // Re-drawing drops that note, so the turn is done here, or every portrait phone photo would lie on its side.
    // 3 = upside down, 6 = turn a quarter clockwise, 8 = a quarter anticlockwise (imagerotate() counts anticlockwise).
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($source);
        $turn = [3 => 180, 6 => -90, 8 => 90][is_array($exif) ? (int) ($exif['Orientation'] ?? 0) : 0] ?? 0;
        $turned = $turn !== 0 ? imagerotate($image, $turn, 0) : false;
        if ($turned) {
            imagedestroy($image);
            $image  = $turned;
            $width  = imagesx($image);
            $height = imagesy($image);
        }
    }

    $scale = min(1, $max_px / max($width, $height));   // never enlarge a small photo
    $new_w = max(1, (int) round($width * $scale));
    $new_h = max(1, (int) round($height * $scale));

    $canvas = imagecreatetruecolor($new_w, $new_h);
    if ($mime === 'image/png') {   // keep a PNG's transparent parts transparent
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
    }
    imagecopyresampled($canvas, $image, 0, 0, 0, 0, $new_w, $new_h, $width, $height);

    $ok = $mime === 'image/png' ? imagepng($canvas, $target, 6) : imagejpeg($canvas, $target, 85);
    imagedestroy($image);
    imagedestroy($canvas);
    return $ok;
}

/**
 * Delete a photo file that an upload saved, e.g. the old avatar after a new one, or a deleted plant's photos.
 * Only paths inside one of the upload folders are touched. The sample photos ('mock/…') live in assets/img and are
 * shared by the demo rows, so they are never deleted.
 */
function delete_upload(?string $path): void
{
    if ($path === null || !preg_match('#^(' . implode('|', UPLOAD_FOLDERS) . ')/[0-9a-f]{32}\.(jpg|png)\z#', $path)) {
        return;
    }
    $file = rtrim(config('upload_dir'), '/\\') . '/' . $path;
    if (is_file($file)) {
        unlink($file);
    }
}
