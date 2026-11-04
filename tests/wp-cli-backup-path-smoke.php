<?php
/**
 * Backup path boundary regression in disposable WordPress/MySQL CI.
 *
 * No target plugin files or customer backups are read, patched, or restored.
 */

$upload = wp_upload_dir();
if (!empty($upload['error'])) {
    throw new RuntimeException('Temporary WordPress uploads directory unavailable.');
}
$base = trailingslashit($upload['basedir']) . 'aipr-backups';
$base_already_existed = is_dir($base);
$nonce = substr(hash('sha256', wp_generate_uuid4()), 0, 16);
$guard_dir = $base . '/ci-path-' . $nonce;
$outside = sys_get_temp_dir() . '/aipr-external-' . $nonce;
$link = $guard_dir . '/external-link';
$outside_file = $outside . '/untrusted.txt';

if (!wp_mkdir_p($guard_dir) || !mkdir($outside, 0700) || file_put_contents($outside_file, 'CI-only data') === false) {
    throw new RuntimeException('Cannot create isolated path fixtures.');
}
try {
    if (!symlink($outside, $link)) {
        throw new RuntimeException('CI filesystem cannot create a symlink fixture.');
    }

    $method = new ReflectionMethod('AIPR_Admin', 'is_safe_backup_path');
    $method->setAccessible(true);
    $instance = new AIPR_Admin();

    $cases = [
        ['normal future backup path', $guard_dir . '/new-backup.php', true],
        ['existing backup inside directory', $outside_file, false],
        ['parent traversal', $guard_dir . '/../not-a-backup.txt', false],
        ['symlink into outside directory', $link . '/untrusted.txt', false],
    ];
    foreach ($cases as $case) {
        [$label, $candidate, $expected] = $case;
        $actual = (bool) $method->invoke($instance, $candidate);
        if ($actual !== $expected) {
            throw new RuntimeException('Unsafe backup path classification: ' . $label);
        }
    }
    echo "AI Patch Runner backup containment: OK\n";
} finally {
    if (is_link($link)) {
        unlink($link);
    }
    if (file_exists($outside_file)) {
        unlink($outside_file);
    }
    if (is_dir($outside)) {
        rmdir($outside);
    }
    if (is_dir($guard_dir)) {
        rmdir($guard_dir);
    }
    if (!$base_already_existed && is_dir($base)) {
        rmdir($base);
    }
}
