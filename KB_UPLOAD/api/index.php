<?php
declare(strict_types=1);
error_reporting(0);          // Never expose PHP errors as plain text in production
ini_set('display_errors', '0');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/EmailService.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/CampaignController.php';
require_once __DIR__ . '/controllers/DonationController.php';
require_once __DIR__ . '/controllers/NgoController.php';
require_once __DIR__ . '/controllers/AdminController.php';

// ── Session setup ────────────────────────────────────────────────────────────
session_name('kb.sid');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_path',     '/');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}

// ── CORS headers ─────────────────────────────────────────────────────────────
$allowedOrigins = array_filter(array_map('trim', explode(',', FRONTEND_ORIGINS)));
$origin         = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} elseif (empty($allowedOrigins)) {
    header('Access-Control-Allow-Origin: *');
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Route parsing ─────────────────────────────────────────────────────────────
$rawUri   = $_SERVER['REQUEST_URI'] ?? '/';
$path     = strtok($rawUri, '?');                       // strip query string
$path     = preg_replace('#^/api#i', '', $path);        // strip /api prefix
$path     = '/' . trim($path, '/');                     // normalise leading slash
$method   = strtoupper($_SERVER['REQUEST_METHOD']);
$segments = array_values(array_filter(explode('/', $path)));
$seg0     = $segments[0] ?? '';
$seg1     = $segments[1] ?? '';
$seg2     = $segments[2] ?? '';
$seg3     = $segments[3] ?? '';

// ── Dispatch ──────────────────────────────────────────────────────────────────
try {

    // Health check
    if ($path === '/health' && $method === 'GET') {
        json_ok(['ok' => true, 'service' => 'kapitbisig-api']);
    }

    /* ── /auth ── */
    if ($seg0 === 'auth') {
        $c = new AuthController();
        match(true) {
            $seg1 === 'signup'          && $method === 'POST' => $c->signup(),
            $seg1 === 'signin'          && $method === 'POST' => $c->signin(),
            $seg1 === 'logout'          && $method === 'POST' => $c->logout(),
            $seg1 === 'me'              && $method === 'GET'  => $c->getMe(),
            $seg1 === 'me'              && $method === 'PUT'  => $c->updateMe(),
            $seg1 === 'forgot-password' && $method === 'POST' => $c->forgotPassword(),
            $seg1 === 'reset-password'  && $method === 'POST' => $c->resetPassword(),
            default => json_error('Not found.', 404),
        };
    }

    /* ── /campaigns ── */
    elseif ($seg0 === 'campaigns') {
        $c = new CampaignController();

        if ($seg1 === '' && $method === 'GET')  { $c->list();           exit; }
        if ($seg1 === '' && $method === 'POST') { $c->create();         exit; }

        // /campaigns/:id
        if ($seg1 !== '' && $seg2 === '') {
            match($method) {
                'GET'    => $c->getById($seg1),
                'PUT'    => $c->update($seg1),
                'DELETE' => $c->delete($seg1),
                default  => json_error('Not found.', 404),
            };
        }

        // /campaigns/:id/:action
        if ($seg1 !== '' && $seg2 !== '') {
            match(true) {
                $seg2 === 'submit'   && $method === 'POST' => $c->submit($seg1),
                $seg2 === 'approve'  && $method === 'POST' => $c->approve($seg1),
                $seg2 === 'reject'   && $method === 'POST' => $c->reject($seg1),
                $seg2 === 'likes'    && $method === 'GET'  => $c->getLikes($seg1),
                $seg2 === 'like'     && $method === 'POST' => $c->toggleLike($seg1),
                $seg2 === 'comments' && $method === 'GET'  => $c->getComments($seg1),
                $seg2 === 'comments' && $method === 'POST' => $c->addComment($seg1),
                default => json_error('Not found.', 404),
            };
        }

        json_error('Not found.', 404);
    }

    /* ── /donations ── */
    elseif ($seg0 === 'donations') {
        $c = new DonationController();

        // POST /donations
        if ($seg1 === '' && $method === 'POST') { $c->create(); exit; }

        // GET /donations/my-donations
        if ($seg1 === 'my-donations' && $method === 'GET') { $c->getMyDonations(); exit; }

        // GET /donations/campaign/:id/donations  OR  /donations/campaign/:id/stats
        if ($seg1 === 'campaign' && $seg2 !== '') {
            match(true) {
                $seg3 === 'donations' && $method === 'GET' => $c->getCampaignDonations($seg2),
                $seg3 === 'stats'     && $method === 'GET' => $c->getCampaignStats($seg2),
                default => json_error('Not found.', 404),
            };
        }

        // GET /donations/:id
        if ($seg1 !== '' && $method === 'GET') { $c->getById($seg1); exit; }

        json_error('Not found.', 404);
    }

    /* ── /ngos ── */
    elseif ($seg0 === 'ngos') {
        $c = new NgoController();

        if ($seg1 === '' && $method === 'GET')           { $c->list();                    exit; }
        if ($seg1 === '' && $method === 'POST')          { $c->create();                  exit; }
        if ($seg1 === 'verified'   && $method === 'GET') { $c->getVerified();             exit; }
        if ($seg1 === 'my-profile' && $method === 'GET') { $c->getMyProfile();            exit; }
        if ($seg1 === 'verification' && $seg2 === 'pending' && $method === 'GET') {
            $c->getPendingVerifications(); exit;
        }

        // /ngos/:id
        if ($seg1 !== '' && $seg2 === '') {
            match($method) {
                'GET'    => $c->getById($seg1),
                'PUT'    => $c->update($seg1),
                'DELETE' => $c->delete($seg1),
                default  => json_error('Not found.', 404),
            };
        }

        // /ngos/:id/:action
        if ($seg1 !== '' && $seg2 !== '') {
            match(true) {
                $seg2 === 'analytics' && $method === 'GET'  => $c->getAnalytics($seg1),
                $seg2 === 'verify'    && $method === 'POST' => $c->verify($seg1),
                $seg2 === 'reject'    && $method === 'POST' => $c->reject($seg1),
                default => json_error('Not found.', 404),
            };
        }

        json_error('Not found.', 404);
    }

    /* ── /admin ── */
    elseif ($seg0 === 'admin') {
        $c = new AdminController();

        if ($seg1 === 'users'          && $method === 'POST') { $c->createUser();       exit; }
        if ($seg1 === 'ngos'           && $method === 'POST') { $c->createNGOProfile(); exit; }
        if ($seg1 === 'users'          && $method === 'GET')  { $c->getUsers();         exit; }
        if ($seg1 === 'activity-logs'  && $method === 'GET' && $seg2 === '') {
            $c->getActivityLogs(); exit;
        }
        if ($seg1 === 'my-activity-logs' && $method === 'GET') { $c->getMyActivityLogs(); exit; }

        // /admin/users/:id/role
        if ($seg1 === 'users' && $seg2 !== '' && $seg3 === 'role' && $method === 'PUT') {
            $c->updateUserRole($seg2); exit;
        }
        // /admin/users/:id  DELETE
        if ($seg1 === 'users' && $seg2 !== '' && $method === 'DELETE') {
            $c->deleteUser($seg2); exit;
        }
        // /admin/activity-logs/:id
        if ($seg1 === 'activity-logs' && $seg2 !== '' && $method === 'GET') {
            $c->getActivityLog($seg2); exit;
        }

        json_error('Not found.', 404);
    }

    else {
        json_error('Not found.', 404);
    }

} catch (PDOException $e) {
    error_log('[DB ERROR] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['message' => 'A database error occurred. Please try again later.']);
    exit;
} catch (Throwable $e) {
    error_log('[SERVER ERROR] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['message' => 'Internal server error.']);
    exit;
}
