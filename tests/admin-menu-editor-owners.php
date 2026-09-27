<?php
/** Lightweight authorization checks without a WordPress installation. */
define('ABSPATH', __DIR__ . '/');

$options = ['admin_email' => 'owner@example.test'];
$users = [
    1 => (object) ['ID' => 1, 'user_email' => 'owner@example.test', 'can_manage' => true],
    2 => (object) ['ID' => 2, 'user_email' => 'client@example.test', 'can_manage' => true],
    3 => (object) ['ID' => 3, 'user_email' => 'reader@example.test', 'can_manage' => false],
];
$current_user_id = 1;
function add_action(...$args): void {}
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function get_userdata($id) { global $users; return $users[$id] ?? false; }
function get_user_by($field, $value) {
    global $users;
    foreach ($users as $user) if ($field === 'email' && $user->user_email === $value) return $user;
    return false;
}
function get_users($args): array {
    global $users;
    return array_slice(array_values(array_filter($users, static fn($user) => $user->can_manage)), 0, (int) $args['number']);
}
function user_can($user, $capability): bool { return $capability === 'manage_options' && $user->can_manage; }
function current_user_can($capability): bool { return user_can(get_userdata(get_current_user_id()), $capability); }
function get_current_user_id(): int { global $current_user_id; return $current_user_id; }
function absint($value): int { return abs((int) $value); }

require dirname(__DIR__) . '/core/module-registry.php';
function assert_access(bool $expected, string $case): void {
    if (wutm_admin_menu_editor_can_edit() !== $expected) throw new RuntimeException($case);
}

assert_access(true, 'Default owner must have access');
$current_user_id = 2;
assert_access(false, 'Another administrator must not inherit access');
$options['wutm_admin_menu_editor_owner_ids'] = [2];
assert_access(true, 'Selected owner must have access');
$current_user_id = 1;
assert_access(false, 'Former owner must lose access');
$options['wutm_admin_menu_editor_owner_ids'] = [3];
assert_access(false, 'Account without manage_options must not become an owner');
$options['wutm_admin_menu_editor_owner_ids'] = [999];
assert_access(false, 'Missing explicit owner must fail closed');
echo "Admin menu editor owner checks passed.\n";
