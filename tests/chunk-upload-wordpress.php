<?php
if (!defined('WP_CLI') || !WP_CLI || getenv('WUTM_MEDIA_INTEGRATION') !== '1' || DB_NAME !== 'wci_fixture') {
    throw new RuntimeException('Disposable WordPress fixture required.');
}
require_once dirname(__DIR__) . '/modules/chunk-upload/includes/class-upload-storage.php';
require_once dirname(__DIR__) . '/modules/chunk-upload/includes/class-uploader.php';

function chunk_fixture_assert($value, string $message): void {
    if (!$value) throw new RuntimeException($message);
}

function chunk_fixture_error(callable $callback, int $status): void {
    try {
        $callback();
    } catch (WUTM_Chunk_Upload_Error $error) {
        chunk_fixture_assert($error->getCode() === $status, 'Expected HTTP ' . $status . ', got ' . $error->getCode() . ': ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected upload to stop with HTTP ' . $status);
}

$fixture_root = sys_get_temp_dir() . '/wutm-fixture-' . wp_generate_uuid4();
mkdir($fixture_root, 0700);
define('WUTM_CHUNK_UPLOAD_TEMP_DIR', $fixture_root);
$store = new WUTM_Chunk_Upload_Storage();
$owner = get_current_user_id();
$attachments = [];
$jobs = [];
$clip = base64_decode(file_get_contents(__DIR__ . '/fixtures/chunk-upload-mp4.base64'), true);
chunk_fixture_assert(is_string($clip) && strlen($clip) > 100, 'Generated MP4 fixture is readable.');
$padding = 2 * MB_IN_BYTES + 37;
$clip .= pack('Na4', $padding + 8, 'free') . str_repeat("\0", $padding);
$digest = hash('sha256', $clip);
try {
    $key = bin2hex(random_bytes(16));
    $init = $store->begin($owner, $key, 'chunk-video-fixture.mp4', strlen($clip), $digest);
    $id = $init['id'];
    $jobs[] = $id;
    chunk_fixture_assert($init['total'] > 2 && $init['received'] === [], 'Video spans multiple chunks.');
    chunk_fixture_error(static function () use ($store,$owner,$id) {
        $store->session($owner + 1, $id, '__return_true');
    }, 404);
    $store->session($owner, $id, static function ($dir, $meta) use ($id,$clip,$store,$owner) {
        chunk_fixture_error(static function () use ($store,$owner,$id) {
            $store->session($owner, $id, '__return_true');
        }, 409);
        chunk_fixture_error(static function () use ($dir,$meta,$id) {
            WUTM_Chunk_Uploader::finish($dir, $meta, $id);
        }, 409);
        foreach (str_split($clip, $meta['part']) as $index=>$content) {
            file_put_contents($dir . '/part-' . $index, $content);
        }
    });
    $resume = $store->begin($owner, $key, 'chunk-video-fixture.mp4', strlen($clip), $digest);
    chunk_fixture_assert($resume['id'] === $id && count($resume['received']) === $init['total'], 'Resume preserves received chunks.');
    $upload = $store->session($owner, $id, static function ($dir,$meta) use ($id) {
        return WUTM_Chunk_Uploader::finish($dir, $meta, $id);
    });
    $attachment = $upload['attachment'];
    $attachments[] = $attachment;
    chunk_fixture_assert(wp_get_attachment_url($attachment) === $upload['url'] &&
        get_post_field('post_author',$attachment) == $owner && get_post_mime_type($attachment) === 'video/mp4', 'Attachment registered for the uploader.');
    chunk_fixture_assert(hash_file('sha256',get_attached_file($attachment)) === $digest &&
        (int) wp_get_attachment_metadata($attachment)['width'] === 32, 'File bytes and video metadata are preserved.');
    $again = $store->session($owner, $id, static function ($dir,$meta) use ($id) {
        chunk_fixture_assert(WUTM_Chunk_Upload_Storage::progress($dir, $meta)['received'] === [], 'Temporary chunks were removed.');
        return WUTM_Chunk_Uploader::finish($dir, $meta, $id);
    });
    chunk_fixture_assert($again['attachment'] === $attachment, 'Repeated finalization returns the same attachment.');
    foreach (['invalid_content','invalid_digest'] as $case) {
        $part = $case === 'invalid_content' ? 'test file with no media structure' : substr($clip, 0, 952);
        $bad = $store->begin($owner, bin2hex(random_bytes(16)), $case . '.mp4', strlen($part),
            $case === 'invalid_digest' ? str_repeat('0', 64) : hash('sha256', $part))['id'];
        $jobs[] = $bad;
        $store->session($owner, $bad, static function ($dir,$meta) use ($part,$bad) {
            file_put_contents($dir . '/part-0', $part);
            chunk_fixture_error(static function () use ($dir,$meta,$bad) {
                WUTM_Chunk_Uploader::finish($dir, $meta, $bad);
            }, 422);
        });
    }
    $also = $store->begin($owner, bin2hex(random_bytes(16)), 'cleanup.mp4', 100, '')['id'];
    $jobs[] = $also;
    $store->session($owner, $also, static function ($dir,$meta) {
        $meta['created'] = time() - 2 * DAY_IN_SECONDS;
        WUTM_Chunk_Upload_Storage::save($dir, $meta);
    });
    $store->cleanup();
    chunk_fixture_error(static function () use ($store,$owner,$also) {
        $store->session($owner, $also, '__return_true');
    }, 404);
    echo "Chunk upload passed: isolation, locking, validation, resumable assembly, metadata, idempotence and expiration.\n";
} finally {
    foreach ($attachments as $attachment) wp_delete_attachment($attachment, true);
    foreach ($jobs as $id) {
        try {
            $store->session($owner, $id, static function ($dir,$meta) {
                $meta['cancelled'] = true;
                WUTM_Chunk_Upload_Storage::save($dir, $meta);
            });
        } catch (WUTM_Chunk_Upload_Error $error) {
            if ($error->getCode() !== 404) throw $error;
        }
    }
    $store->cleanup();
    foreach (glob($fixture_root . '/wutm-chunks-*', GLOB_ONLYDIR) ?: [] as $dir) {
        unlink($dir . '/.lock');
        rmdir($dir);
    }
    rmdir($fixture_root);
}
