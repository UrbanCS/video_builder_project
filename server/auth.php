<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

function redirectToHome(): never
{
    header('Location: ' . BASE_URL);
    exit;
}

ensureSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectToHome();
}

$action = (string) ($_POST['action'] ?? '');

try {
    if ($action === 'bootstrap_owner') {
        if (usersExist()) {
            throw new RuntimeException('Owner account already initialized');
        }
        $email = (string) ($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $user = createUser($email, $password, 'owner');
        loginUser($user);
        $_SESSION['flash_success'] = 'Compte propriétaire créé.';
        redirectToHome();
    }

    if ($action === 'login') {
        $email = (string) ($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $user = authenticateUser($email, $password);
        if ($user === null) {
            throw new RuntimeException('Email ou mot de passe invalide');
        }
        loginUser($user);
        $_SESSION['flash_success'] = 'Connexion réussie.';
        redirectToHome();
    }

    if ($action === 'logout') {
        logoutUser();
        ensureSession();
        $_SESSION['flash_success'] = 'Déconnecté.';
        redirectToHome();
    }

    if ($action === 'create_client') {
        $user = currentUser();
        if (!isOwner($user)) {
            throw new RuntimeException('Accès refusé');
        }
        $email = (string) ($_POST['email'] ?? '');
        $passwordInput = trim((string) ($_POST['password'] ?? ''));
        $password = $passwordInput !== '' ? $passwordInput : bin2hex(random_bytes(8));
        $profile = [
            'client_first_name' => (string) ($_POST['client_first_name'] ?? ''),
            'client_last_name' => (string) ($_POST['client_last_name'] ?? ''),
            'tribute_name' => (string) ($_POST['tribute_name'] ?? ''),
        ];
        $createdUser = createUser($email, $password, 'client', (string) ($user['id'] ?? ''), $profile);

        $token = issuePasswordResetToken((string) ($createdUser['email'] ?? ''));
        $inviteSent = false;
        if ($token !== null) {
            $inviteSent = sendClientInviteEmail((string) ($createdUser['email'] ?? ''), $token);
        }

        if ($inviteSent) {
            $_SESSION['flash_success'] = 'Compte client créé. Un courriel d’invitation a été envoyé. Vérifiez votre boîte à pourriel.';
        } else {
            $_SESSION['flash_success'] = 'Compte client créé. Le courriel d’invitation n’a pas pu être envoyé; transmettez un lien de réinitialisation manuellement.';
        }
        redirectToHome();
    }

    if ($action === 'register_client') {
        if (!defined('ALLOW_PUBLIC_SIGNUP') || ALLOW_PUBLIC_SIGNUP !== true) {
            throw new RuntimeException('Création de compte indisponible');
        }
        $email = (string) ($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        createUser($email, $password, 'client');
        $_SESSION['flash_success'] = 'Compte créé. Vous pouvez vous connecter.';
        redirectToHome();
    }

    if ($action === 'forgot_password') {
        $email = (string) ($_POST['email'] ?? '');
        $token = issuePasswordResetToken($email);
        if ($token !== null) {
            sendPasswordResetEmail(normalizeEmail($email), $token);
        }
        $_SESSION['flash_success'] = 'Si ce courriel existe, un lien de réinitialisation a été envoyé. Assurez-vous de surveiller votre boîte à pourriel.';
        redirectToHome();
    }

    if ($action === 'reset_password') {
        $token = (string) ($_POST['reset_token'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $ok = resetPasswordByToken($token, $password);
        if (!$ok) {
            throw new RuntimeException('Lien de réinitialisation invalide ou expiré');
        }
        $_SESSION['flash_success'] = 'Mot de passe réinitialisé. Vous pouvez vous connecter.';
        redirectToHome();
    }

    throw new RuntimeException('Action invalide');
} catch (Throwable $e) {
    $_SESSION['flash_error'] = $e->getMessage();
    redirectToHome();
}
