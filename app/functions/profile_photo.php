<?php
/**
 * Hardened profile-photo save.
 *
 * Security: the stored file's extension is chosen by the SERVER from the real
 * detected image type (jpg/png/webp), never from the user-supplied filename.
 * Since nginx only hands *.php to PHP-FPM, a file stored as .jpg/.png/.webp can
 * never be executed — so even an image/PHP polyglot is inert. We also verify the
 * bytes are a genuine raster image (getimagesize), cap the size, and require a
 * real multipart upload.
 *
 * @return array{0: bool|null, 1: ?string}
 *   [true, $filename]  saved OK (store $filename in users.ProfilePhoto)
 *   [false, $message]  validation/IO error (show $message)
 *   [null, null]       no file was submitted (leave the existing photo as-is)
 */
function save_profile_photo($file, int $empID): array {
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [false, 'Photo upload failed. Please try again.'];
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return [false, 'Photo must be under 2 MB.'];
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return [false, 'Invalid upload.'];
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return [false, "That file isn't a valid image."];
    }
    $extByType = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];
    $type = $info[2] ?? 0;
    if (!isset($extByType[$type])) {
        return [false, 'Only JPG, PNG, or WEBP images are allowed.'];
    }
    $ext = $extByType[$type];

    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return [false, 'Photo storage is not available. Contact an administrator.'];
    }
    // Clear any prior profile_<id>.* so we don't leave a stale extension behind.
    foreach (glob($dir . '/profile_' . $empID . '.*') ?: [] as $old) {
        @unlink($old);
    }
    $filename = 'profile_' . $empID . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
        return [false, 'Could not save the photo.'];
    }
    @chmod($dir . '/' . $filename, 0644);
    return [true, $filename];
}
