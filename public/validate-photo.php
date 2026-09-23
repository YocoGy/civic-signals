<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    \App\Security\CSRF::validate(
        (string)($_POST['_csrf'] ?? '')
    );

    if (
        $_SERVER['REQUEST_METHOD'] !== 'POST'
        || !isset($_FILES['photo'])
        || is_array($_FILES['photo']['name'])
        || $_FILES['photo']['error'] !== UPLOAD_ERR_OK
        || !is_uploaded_file(
            $_FILES['photo']['tmp_name']
        )
    ) {
        throw new RuntimeException(
            'Изберете една валидна снимка.'
        );
    }

    $data = \App\Services\PhotoInspector::inspect(
        $_FILES['photo']['tmp_name'],
        (string)$_FILES['photo']['name']
    );

    json_response([
        'valid' => true,
        'message' =>
            'Снимката е валидна. GPS координатите и датата и часът на заснемане са открити в EXIF данните.',
        'filename' =>
            basename(
                (string)$_FILES['photo']['name']
            ),
        'size' =>
            $data['file_size']
    ]);

} catch (Throwable $e) {

    json_response([
        'valid' => false,
        'message' => $e->getMessage()
    ], 422);
}