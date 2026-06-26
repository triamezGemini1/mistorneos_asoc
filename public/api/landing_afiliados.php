<?php



declare(strict_types=1);



require_once __DIR__ . '/../../config/bootstrap.php';

require_once __DIR__ . '/../../config/db.php';

require_once __DIR__ . '/../../lib/app_helpers.php';

require_once __DIR__ . '/../../lib/Branding.php';

require_once __DIR__ . '/../../lib/LandingAfiliadosService.php';

require_once __DIR__ . '/../../lib/LandingAfiliadosAccess.php';



header('Content-Type: application/json; charset=utf-8');

header('Access-Control-Allow-Origin: *');



$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$baseUrl = rtrim(AppHelpers::getPublicUrl(), '/') . '/';

$service = new LandingAfiliadosService(DB::pdo());



$brandPayload = [

    'name' => Branding::siteName(),

    'tagline' => Branding::tagline(),

    'logo_url' => Branding::logoUrl(),

];



/**

 * @param array<string, mixed> $payload

 *

 * @return array<string, mixed>

 */

function landingAfiliadosPayload(array $payload, int $orgId = 0): array

{

    $payload['access'] = $payload['access'] ?? LandingAfiliadosAccess::context($orgId);



    return $payload;

}



/**

 * @param array<string, mixed> $deny

 */

function landingAfiliadosDeny(array $deny): void

{

    $code = (string) ($deny['code'] ?? '');

    if ($code === 'usuario_solo_perfil') {

        http_response_code(403);

    } elseif ($code === 'requiere_admin') {

        http_response_code(401);

    } else {

        http_response_code(403);

    }

    echo json_encode($deny, JSON_UNESCAPED_UNICODE);

}



try {

    if ($method === 'POST') {

        $input = json_decode((string) file_get_contents('php://input'), true);

        if (! is_array($input)) {

            $input = $_POST;

        }



        $action = trim((string) ($input['action'] ?? ''));

        $orgId = (int) ($input['org_id'] ?? 0);

        $clubId = (int) ($input['club_id'] ?? 0);

        $userId = (int) ($input['user_id'] ?? 0);



        $deny = LandingAfiliadosAccess::guard($action, $orgId);

        if ($deny !== null) {

            landingAfiliadosDeny($deny);

            exit;

        }



        if ($action === 'toggle_afiliado') {

            $result = $service->toggleAfiliadoStatus($orgId, $clubId, $userId);

            if (! ($result['success'] ?? false)) {

                http_response_code(403);

            }

            echo json_encode($result, JSON_UNESCAPED_UNICODE);

            exit;

        }



        throw new InvalidArgumentException('Acción no válida');

    }



    if ($method !== 'GET') {

        http_response_code(405);

        echo json_encode(['success' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);

        exit;

    }



    $action = trim((string) ($_GET['action'] ?? 'list'));

    $orgId = (int) ($_GET['org_id'] ?? 0);

    $torneoId = (int) ($_GET['torneo_id'] ?? 0);

    $clubId = (int) ($_GET['club_id'] ?? 0);

    $userId = (int) ($_GET['user_id'] ?? 0);



    $deny = LandingAfiliadosAccess::guard($action, $orgId);

    if ($deny !== null) {

        landingAfiliadosDeny($deny);

        exit;

    }



    $payload = landingAfiliadosPayload([

        'success' => true,

        'brand' => $brandPayload,

        'base_url' => $baseUrl,

    ], $orgId);



    switch ($action) {

        case 'list':

            $payload['afiliados'] = $service->listAfiliadosActivos(false);

            break;



        case 'hub':

            if ($orgId <= 0) {

                throw new InvalidArgumentException('org_id requerido');

            }

            $hub = $service->getAfiliadoHub($orgId);

            if ($hub === null) {

                http_response_code(404);

                echo json_encode(['success' => false, 'error' => 'Afiliado no encontrado'], JSON_UNESCAPED_UNICODE);

                exit;

            }

            $payload = array_merge($payload, $hub);

            break;



        case 'torneos':

            if ($orgId <= 0) {

                throw new InvalidArgumentException('org_id requerido');

            }

            $torneos = $service->getTorneosPublicos($orgId);

            if ($torneos === null) {

                http_response_code(404);

                echo json_encode(['success' => false, 'error' => 'Afiliado no encontrado'], JSON_UNESCAPED_UNICODE);

                exit;

            }

            $payload['torneos'] = $torneos;

            break;



        case 'torneo':

            if ($orgId <= 0 || $torneoId <= 0) {

                throw new InvalidArgumentException('org_id y torneo_id requeridos');

            }

            $detalle = $service->getTorneoDetalle($orgId, $torneoId, $baseUrl);

            if ($detalle === null) {

                http_response_code(404);

                echo json_encode(['success' => false, 'error' => 'Torneo no encontrado'], JSON_UNESCAPED_UNICODE);

                exit;

            }

            $payload['torneo'] = $detalle;

            $payload['urls'] = [

                'resultados' => $baseUrl . 'evento_resultados.php?torneo_id=' . $torneoId . '&organizacion_id=' . $orgId,

                'detalle_legacy' => $baseUrl . 'torneo_detalle.php?torneo_id=' . $torneoId . '&organizacion_id=' . $orgId,

            ];

            break;



        case 'clubes':

            if ($orgId <= 0) {

                throw new InvalidArgumentException('org_id requerido');

            }

            $clubes = $service->getClubesPublicos($orgId);

            if ($clubes === null) {

                http_response_code(404);

                echo json_encode(['success' => false, 'error' => 'Afiliado no encontrado'], JSON_UNESCAPED_UNICODE);

                exit;

            }

            $payload = array_merge($payload, $clubes);

            break;



        case 'club':

            if ($orgId <= 0 || $clubId <= 0) {

                throw new InvalidArgumentException('org_id y club_id requeridos');

            }

            $club = $service->getClubDetalle($orgId, $clubId);

            if ($club === null) {

                http_response_code(404);

                echo json_encode(['success' => false, 'error' => 'Club no encontrado'], JSON_UNESCAPED_UNICODE);

                exit;

            }

            $payload = array_merge($payload, $club);

            break;



        case 'afiliado':

            if ($orgId <= 0 || $clubId <= 0 || $userId <= 0) {

                throw new InvalidArgumentException('org_id, club_id y user_id requeridos');

            }

            $afiliado = $service->getAfiliadoEnClub($orgId, $clubId, $userId);

            if ($afiliado === null) {

                http_response_code(404);

                echo json_encode(['success' => false, 'error' => 'Afiliado no encontrado'], JSON_UNESCAPED_UNICODE);

                exit;

            }

            $payload = array_merge($payload, $afiliado);

            break;



        default:

            throw new InvalidArgumentException('Acción no válida');

    }



    echo json_encode($payload, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    http_response_code(400);

    echo json_encode([

        'success' => false,

        'error' => $e->getMessage(),

    ], JSON_UNESCAPED_UNICODE);

}


