<?php
function save_uploaded_image(array $file, string $subdirectory = 'uploads'): string
{
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Invalid image upload.');
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed. Please try again.');
    }

    if (($file['size'] ?? 0) <= 0 || $file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Images must be smaller than 5 MB.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Please upload a JPG, PNG, or WEBP image.');
    }

    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subdirectory);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('The image upload directory could not be created.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('The image could not be saved. Please try again.');
    }

    return trim($subdirectory, '/') . '/' . $filename;
}

function delete_uploaded_image(?string $relativePath): void
{
    if (!$relativePath || strpos($relativePath, 'uploads/') !== 0) {
        return;
    }

    $uploadRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');
    $path = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    if (!$uploadRoot || !$path || strpos($path, $uploadRoot . DIRECTORY_SEPARATOR) !== 0) {
        return;
    }

    if (is_file($path)) {
        if (!unlink($path)) {
            error_log('Unable to remove uploaded image: ' . $path);
        }
    }
}

function save_uploaded_images(array $files, string $subdirectory = 'uploads', int $limit = 5): array
{
    $saved = [];
    $count = count($files['name'] ?? []);
    if ($count > $limit) {
        throw new RuntimeException('You can upload at most ' . $limit . ' additional photos at a time.');
    }

    try {
        for ($index = 0; $index < $count; $index++) {
            if (($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $saved[] = save_uploaded_image([
                'name' => $files['name'][$index],
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ], $subdirectory);
        }
    } catch (RuntimeException $exception) {
        foreach ($saved as $path) {
            delete_uploaded_image($path);
        }
        throw $exception;
    }

    return $saved;
}
