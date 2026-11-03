<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$base = '/takoyaki-shop-infoman';
$page = 'admin';
$adminPage = 'users';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $error = null;

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try that action again.';
    } elseif (!$conn || ($userId && $userId === (int) $_SESSION['user_id'])) {
        $error = 'This user action is not valid.';
    } elseif ($action === 'deactivate') {
        if (!$userId) {
            $error = 'Select a valid user.';
        } else {
            mysqli_begin_transaction($conn);
            $targetStmt = mysqli_prepare($conn, 'SELECT role, status FROM users WHERE user_id = ? FOR UPDATE');
            $target = null;
            if ($targetStmt) {
                mysqli_stmt_bind_param($targetStmt, 'i', $userId);
                mysqli_stmt_execute($targetStmt);
                $target = mysqli_fetch_assoc(mysqli_stmt_get_result($targetStmt));
                mysqli_stmt_close($targetStmt);
            }
            if (!$target || $target['status'] !== 'active') {
                $error = 'This user is not active.';
            } elseif ($target['role'] === 'admin') {
                $admins = mysqli_query($conn, "SELECT user_id FROM users WHERE role = 'admin' AND status = 'active' FOR UPDATE");
                if (!$admins || mysqli_num_rows($admins) <= 1) {
                    $error = 'The last active admin account cannot be deactivated.';
                }
            }
            if ($error === null) {
                $stmt = mysqli_prepare($conn, "UPDATE users SET status = 'deactivated' WHERE user_id = ? AND status = 'active'");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'i', $userId);
                    if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) !== 1) {
                        $error = 'The active user could not be deactivated.';
                        error_log('User deactivation failed: ' . mysqli_stmt_error($stmt));
                    }
                    mysqli_stmt_close($stmt);
                } else {
                    $error = 'Unable to deactivate this user.';
                    error_log('User deactivation preparation failed: ' . mysqli_error($conn));
                }
            }
            if ($error === null) {
                mysqli_commit($conn);
            } else {
                mysqli_rollback($conn);
            }
        }
    } elseif ($action === 'activate' && $userId) {
        $stmt = mysqli_prepare($conn, "UPDATE users SET status = 'active' WHERE user_id = ? AND status = 'deactivated'");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $userId);
            if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) !== 1) {
                $error = 'Unable to reactivate this account.';
                error_log('User reactivation failed: ' . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);
        } else {
            $error = 'Unable to reactivate this account.';
            error_log('User reactivation preparation failed: ' . mysqli_error($conn));
        }
    } elseif ($action === 'role' && $userId && in_array($_POST['role'] ?? '', ['customer', 'admin'], true)) {
        $newRole = $_POST['role'];
        mysqli_begin_transaction($conn);
        $check = mysqli_prepare($conn, 'SELECT role, status FROM users WHERE user_id = ? FOR UPDATE');
        $active = 'active';
        if ($check) {
            mysqli_stmt_bind_param($check, 'i', $userId);
            mysqli_stmt_execute($check);
            $target = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
            mysqli_stmt_close($check);
            if (!$target || $target['status'] !== 'active') {
                $error = 'Only active users can have their role changed.';
            } elseif ($target['role'] === 'admin' && $newRole !== 'admin') {
                $admins = mysqli_query($conn, "SELECT user_id FROM users WHERE role = 'admin' AND status = 'active' FOR UPDATE");
                if (!$admins || mysqli_num_rows($admins) <= 1) {
                    $error = 'At least one active admin account must remain.';
                }
            }
        } else {
            $error = 'Unable to check the selected user.';
            error_log('User role lookup preparation failed: ' . mysqli_error($conn));
        }

        if ($error === null) {
            $update = mysqli_prepare($conn, 'UPDATE users SET role = ? WHERE user_id = ? AND status = ?');
            if ($update) {
                mysqli_stmt_bind_param($update, 'sis', $newRole, $userId, $active);
                if (!mysqli_stmt_execute($update)) {
                    $error = 'Unable to update the user role.';
                    error_log('User role update failed: ' . mysqli_stmt_error($update));
                }
                mysqli_stmt_close($update);
            } else {
                $error = 'Unable to update the user role.';
                error_log('User role update preparation failed: ' . mysqli_error($conn));
            }
        }
        if ($error === null) {
            mysqli_commit($conn);
        } else {
            mysqli_rollback($conn);
        }
    } elseif ($action === 'save_admin') {
        $emailInput = $_POST['email'] ?? '';
        $nameInput = $_POST['full_name'] ?? '';
        $passwordInput = $_POST['password'] ?? '';
        $email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
        $fullName = is_string($nameInput) ? trim($nameInput) : '';
        $password = is_string($passwordInput) ? $passwordInput : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100 || $fullName === '' || strlen($fullName) > 100) {
            $error = 'Enter a valid admin name and email address.';
        } elseif (!$userId && strlen($password) < 6) {
            $error = 'New admin passwords must be at least 6 characters.';
        } elseif ($userId && $password !== '' && strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } else {
            $duplicate = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1');
            $excludeId = $userId ?: 0;
            if ($duplicate) {
                mysqli_stmt_bind_param($duplicate, 'si', $email, $excludeId);
                mysqli_stmt_execute($duplicate);
                $emailTaken = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($duplicate));
                mysqli_stmt_close($duplicate);
                if ($emailTaken) {
                    $error = 'That email address is already in use.';
                }
            } else {
                $error = 'Unable to verify the admin email.';
                error_log('Admin email check preparation failed: ' . mysqli_error($conn));
            }

            if ($error === null) {
                mysqli_begin_transaction($conn);
                if ($userId) {
                    $target = mysqli_prepare($conn, "SELECT role FROM users WHERE user_id = ? FOR UPDATE");
                    if ($target) {
                        mysqli_stmt_bind_param($target, 'i', $userId);
                        mysqli_stmt_execute($target);
                        $targetUser = mysqli_fetch_assoc(mysqli_stmt_get_result($target));
                        mysqli_stmt_close($target);
                        if (!$targetUser || $targetUser['role'] !== 'admin') {
                            $error = 'Only other admin accounts can be edited here.';
                        }
                    } else {
                        $error = 'Unable to load that admin account.';
                    }
                }

                if ($error === null && $userId) {
                    $updateSql = $password === ''
                        ? 'UPDATE users SET email = ? WHERE user_id = ?'
                        : 'UPDATE users SET email = ?, password = ? WHERE user_id = ?';
                    $update = mysqli_prepare($conn, $updateSql);
                    if ($update) {
                        if ($password === '') {
                            mysqli_stmt_bind_param($update, 'si', $email, $userId);
                        } else {
                            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                            mysqli_stmt_bind_param($update, 'ssi', $email, $passwordHash, $userId);
                        }
                        if (!mysqli_stmt_execute($update)) {
                            $error = 'Unable to update the admin account.';
                            error_log('Admin update failed: ' . mysqli_stmt_error($update));
                        }
                        mysqli_stmt_close($update);
                    } else {
                        $error = 'Unable to update the admin account.';
                    }
                } elseif ($error === null) {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $role = 'admin';
                    $status = 'active';
                    $insert = mysqli_prepare($conn, 'INSERT INTO users (email, password, role, status) VALUES (?, ?, ?, ?)');
                    if ($insert) {
                        mysqli_stmt_bind_param($insert, 'ssss', $email, $passwordHash, $role, $status);
                        if (!mysqli_stmt_execute($insert)) {
                            $error = 'Unable to create the admin account.';
                            error_log('Admin creation failed: ' . mysqli_stmt_error($insert));
                        } else {
                            $userId = mysqli_insert_id($conn);
                        }
                        mysqli_stmt_close($insert);
                    } else {
                        $error = 'Unable to create the admin account.';
                    }
                }

                if ($error === null) {
                    $profile = mysqli_prepare($conn, 'INSERT INTO customer_profile (user_id, full_name) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE full_name = VALUES(full_name)');
                    if ($profile) {
                        mysqli_stmt_bind_param($profile, 'is', $userId, $fullName);
                        if (!mysqli_stmt_execute($profile)) {
                            $error = 'Unable to save the admin name.';
                            error_log('Admin profile save failed: ' . mysqli_stmt_error($profile));
                        }
                        mysqli_stmt_close($profile);
                    } else {
                        $error = 'Unable to save the admin name.';
                    }
                }

                if ($error === null) {
                    mysqli_commit($conn);
                } else {
                    mysqli_rollback($conn);
                }
            }
        }
    } else {
        $error = 'Select a valid user action.';
    }

    $_SESSION['message'] = $error ?? 'User account updated.';
    $_SESSION['msg_type'] = $error === null ? 'success' : 'danger';
    header('Location: ' . $base . '/admin/users.php');
    exit();
}

$users = [];
$queryError = false;
if ($conn) {
    $result = mysqli_query($conn, 'SELECT u.user_id, u.email, u.role, u.status, u.created_at,
        cp.full_name, cp.contact_number
        FROM users u LEFT JOIN customer_profile cp ON cp.user_id = u.user_id
        ORDER BY u.role DESC, u.created_at DESC');
    if ($result) {
        while ($user = mysqli_fetch_assoc($result)) {
            $users[] = $user;
        }
    } else {
        $queryError = true;
        error_log('Admin user list query failed: ' . mysqli_error($conn));
    }
} else {
    $queryError = true;
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/adminHeader.php';
?>

<div class="page-title">
    <div>
        <div class="eyebrow">Admin management</div>
        <h1>User accounts</h1>
        <p>Manage account status and the roles of active users.</p>
    </div>
</div>

<?php include __DIR__ . '/../includes/alert.php'; ?>

<section class="panel">
    <h2>Create admin account</h2>
    <form method="POST" class="form-grid" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_admin">
        <div class="field"><label for="admin_name">Name</label><input id="admin_name" name="full_name" maxlength="100"></div>
        <div class="field"><label for="admin_email">Email</label><input id="admin_email" name="email" type="email" maxlength="100"></div>
        <div class="field"><label for="admin_password">Password (at least 6 characters)</label><input id="admin_password" name="password" type="password"></div>
        <div class="field"><button type="submit">Create admin account</button></div>
    </form>
</section>

<section class="panel">
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>User</th><th>Email</th><th>Role</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php if ($queryError) { ?>
                    <tr><td colspan="5" class="empty">Unable to load users right now.</td></tr>
                <?php } elseif (!$users) { ?>
                    <tr><td colspan="5" class="empty">No user accounts found.</td></tr>
                <?php } else { foreach ($users as $user) { ?>
                    <tr>
                        <td><?= htmlspecialchars($user['full_name'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <td><?= htmlspecialchars(ucfirst($user['role'])) ?></td>
                        <td><?= htmlspecialchars(ucfirst($user['status'])) ?></td>
                        <td>
                            <?php if ((int) $user['user_id'] !== (int) $_SESSION['user_id']) { ?>
                                <div class="actions">
                                    <?php if ($user['status'] === 'active') { ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                            <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                                            <input type="hidden" name="action" value="role">
                                            <select name="role" aria-label="Role for <?= htmlspecialchars($user['email']) ?>">
                                                <option value="customer" <?= $user['role'] === 'customer' ? 'selected' : '' ?>>Customer</option>
                                                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                            </select>
                                            <button type="submit" class="small">Update role</button>
                                        </form>
                                        <form method="POST" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                        <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                                        <input type="hidden" name="action" value="deactivate">
                                        <button type="submit" class="small danger">Deactivate</button>
                                        </form>
                                    <?php } else { ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                            <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                                            <input type="hidden" name="action" value="activate">
                                            <button type="submit" class="small">Reactivate</button>
                                        </form>
                                    <?php } ?>
                                </div>
                                <?php if ($user['role'] === 'admin') { ?>
                                    <details><summary>Edit admin / change password</summary>
                                        <form method="POST" class="form-grid" novalidate>
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="save_admin">
                                            <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                                            <div class="field"><label>Name</label><input name="full_name" maxlength="100" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>"></div>
                                            <div class="field"><label>Email</label><input name="email" type="email" maxlength="100" value="<?= htmlspecialchars($user['email']) ?>"></div>
                                            <div class="field"><label>New password (leave blank to keep current)</label><input name="password" type="password"></div>
                                            <div class="field"><button class="small" type="submit">Save admin</button></div>
                                        </form>
                                    </details>
                                <?php } ?>
                            <?php } else { ?><span class="muted">No actions available</span><?php } ?>
                        </td>
                    </tr>
                <?php } } ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
