<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';

Auth::requireRole(['admin_general']);

$action = $_GET['action'] ?? 'index';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    if ($csrf === '' || $sessionToken === '' || !hash_equals($sessionToken, $csrf)) {
        header('Location: index.php?page=entidades&error=' . urlencode('Token CSRF inválido'));
        exit;
    }

    $postAction = (string)($_POST['crud_action'] ?? '');
    $pdo = DB::pdo();

    try {
        if ($postAction === 'crear_estructura_asociaciones') {
            $colsEntidad = $pdo->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
            $codeCol = null;
            $nameCol = null;
            $stateCol = null;
            foreach ($colsEntidad as $col) {
                $field = strtolower((string)($col['Field'] ?? ''));
                if ($codeCol === null && in_array($field, ['id', 'codigo', 'cod_entidad', 'code'], true)) {
                    $codeCol = (string)$col['Field'];
                }
                if ($nameCol === null && in_array($field, ['nombre', 'descripcion', 'entidad', 'nombre_entidad'], true)) {
                    $nameCol = (string)$col['Field'];
                }
                if ($stateCol === null && in_array($field, ['estado', 'estatus', 'status', 'activo'], true)) {
                    $stateCol = (string)$col['Field'];
                }
            }
            if ($codeCol === null || $nameCol === null) {
                throw new RuntimeException('No se pudo detectar la estructura de la tabla entidad');
            }

            $sqlEntidades = "SELECT {$codeCol} AS codigo, {$nameCol} AS nombre FROM entidad";
            if ($stateCol !== null) {
                $sqlEntidades .= " WHERE {$stateCol} = 1";
            }
            $sqlEntidades .= " ORDER BY {$nameCol} ASC";
            $entidades = $pdo->query($sqlEntidades)->fetchAll(PDO::FETCH_ASSOC);
            if (!$entidades) {
                throw new RuntimeException('No hay asociaciones activas para procesar');
            }

            $colsOrgRaw = $pdo->query("SHOW COLUMNS FROM organizaciones")->fetchAll(PDO::FETCH_ASSOC);
            $orgCols = array_map(static fn(array $c): string => strtolower((string)($c['Field'] ?? '')), $colsOrgRaw);
            $hasOrgDireccion = in_array('direccion', $orgCols, true);
            $hasOrgResponsable = in_array('responsable', $orgCols, true);
            $hasOrgTelefono = in_array('telefono', $orgCols, true);
            $hasOrgEmail = in_array('email', $orgCols, true);
            $hasOrgTipo = in_array('tipo_org', $orgCols, true);
            $hasOrgCreatedAt = in_array('created_at', $orgCols, true);
            $hasOrgUpdatedAt = in_array('updated_at', $orgCols, true);

            $colsClubRaw = $pdo->query("SHOW COLUMNS FROM clubes")->fetchAll(PDO::FETCH_ASSOC);
            $clubCols = array_map(static fn(array $c): string => strtolower((string)($c['Field'] ?? '')), $colsClubRaw);
            $hasClubDireccion = in_array('direccion', $clubCols, true);
            $hasClubDelegado = in_array('delegado', $clubCols, true);
            $hasClubTelefono = in_array('telefono', $clubCols, true);
            $hasClubEmail = in_array('email', $clubCols, true);
            $hasClubEntidad = in_array('entidad', $clubCols, true);
            $hasClubInscLinea = in_array('permite_inscripcion_linea', $clubCols, true);

            $adminUserId = (int)$pdo->query("SELECT id FROM usuarios WHERE role = 'admin_general' AND status = 0 ORDER BY id ASC LIMIT 1")->fetchColumn();
            if ($adminUserId <= 0) {
                $adminUserId = (int)$pdo->query("SELECT id FROM usuarios ORDER BY id ASC LIMIT 1")->fetchColumn();
            }
            if ($adminUserId <= 0) {
                throw new RuntimeException('No hay usuario disponible para admin_user_id en organizaciones');
            }

            $orgFields = ['nombre', 'entidad', 'admin_user_id', 'estatus'];
            if ($hasOrgDireccion) { $orgFields[] = 'direccion'; }
            if ($hasOrgResponsable) { $orgFields[] = 'responsable'; }
            if ($hasOrgTelefono) { $orgFields[] = 'telefono'; }
            if ($hasOrgEmail) { $orgFields[] = 'email'; }
            if ($hasOrgTipo) { $orgFields[] = 'tipo_org'; }
            if ($hasOrgCreatedAt) { $orgFields[] = 'created_at'; }
            if ($hasOrgUpdatedAt) { $orgFields[] = 'updated_at'; }

            $orgPlaceholders = array_map(static fn(string $f): string => ':' . $f, $orgFields);
            $insertOrgSql = "INSERT INTO organizaciones (" . implode(', ', $orgFields) . ") VALUES (" . implode(', ', $orgPlaceholders) . ")";
            $insertOrgStmt = $pdo->prepare($insertOrgSql);

            $clubFields = ['nombre', 'organizacion_id', 'estatus'];
            if ($hasClubDireccion) { $clubFields[] = 'direccion'; }
            if ($hasClubDelegado) { $clubFields[] = 'delegado'; }
            if ($hasClubTelefono) { $clubFields[] = 'telefono'; }
            if ($hasClubEmail) { $clubFields[] = 'email'; }
            if ($hasClubEntidad) { $clubFields[] = 'entidad'; }
            if ($hasClubInscLinea) { $clubFields[] = 'permite_inscripcion_linea'; }

            $clubPlaceholders = array_map(static fn(string $f): string => ':' . $f, $clubFields);
            $insertClubSql = "INSERT INTO clubes (" . implode(', ', $clubFields) . ") VALUES (" . implode(', ', $clubPlaceholders) . ")";
            $insertClubStmt = $pdo->prepare($insertClubSql);

            $createdOrgs = 0;
            $createdClubs = 0;

            $pdo->beginTransaction();
            foreach ($entidades as $entidad) {
                $codigo = (int)($entidad['codigo'] ?? 0);
                $nombre = trim((string)($entidad['nombre'] ?? ''));
                if ($codigo <= 0 || $nombre === '') {
                    continue;
                }

                $orgId = 0;
                $checkOrgSql = "SELECT id FROM organizaciones WHERE entidad = ?";
                if ($hasOrgTipo) {
                    $checkOrgSql .= " AND tipo_org = 0";
                }
                $checkOrgSql .= " ORDER BY id ASC LIMIT 1";
                $checkOrgStmt = $pdo->prepare($checkOrgSql);
                $checkOrgStmt->execute([$codigo]);
                $orgId = (int)$checkOrgStmt->fetchColumn();

                if ($orgId <= 0) {
                    $orgParams = [
                        ':nombre' => $nombre,
                        ':entidad' => $codigo,
                        ':admin_user_id' => $adminUserId,
                        ':estatus' => 1,
                    ];
                    if ($hasOrgDireccion) { $orgParams[':direccion'] = null; }
                    if ($hasOrgResponsable) { $orgParams[':responsable'] = null; }
                    if ($hasOrgTelefono) { $orgParams[':telefono'] = null; }
                    if ($hasOrgEmail) { $orgParams[':email'] = null; }
                    if ($hasOrgTipo) { $orgParams[':tipo_org'] = 0; }
                    if ($hasOrgCreatedAt) { $orgParams[':created_at'] = date('Y-m-d H:i:s'); }
                    if ($hasOrgUpdatedAt) { $orgParams[':updated_at'] = date('Y-m-d H:i:s'); }
                    $insertOrgStmt->execute($orgParams);
                    $orgId = (int)$pdo->lastInsertId();
                    $createdOrgs++;
                }

                $checkClubSql = "SELECT id FROM clubes WHERE organizacion_id = ? AND LOWER(TRIM(nombre)) = LOWER(TRIM(?)) LIMIT 1";
                $checkClubStmt = $pdo->prepare($checkClubSql);
                $checkClubStmt->execute([$orgId, $nombre]);
                $clubId = (int)$checkClubStmt->fetchColumn();

                if ($clubId <= 0) {
                    $clubParams = [
                        ':nombre' => $nombre,
                        ':organizacion_id' => $orgId,
                        ':estatus' => 1,
                    ];
                    if ($hasClubDireccion) { $clubParams[':direccion'] = null; }
                    if ($hasClubDelegado) { $clubParams[':delegado'] = null; }
                    if ($hasClubTelefono) { $clubParams[':telefono'] = null; }
                    if ($hasClubEmail) { $clubParams[':email'] = null; }
                    if ($hasClubEntidad) { $clubParams[':entidad'] = $codigo; }
                    if ($hasClubInscLinea) { $clubParams[':permite_inscripcion_linea'] = 0; }
                    $insertClubStmt->execute($clubParams);
                    $createdClubs++;
                }
            }
            $pdo->commit();

            $msg = "Proceso completado. Organizaciones creadas: {$createdOrgs}. Clubes creados: {$createdClubs}.";
            header('Location: index.php?page=entidades&success=' . urlencode($msg));
            exit;
        }

        if ($postAction === 'create') {
            $codigo = (int)($_POST['codigo'] ?? 0);
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $estado = !empty($_POST['estado']) ? 1 : 0;
            if ($codigo <= 0 || $nombre === '') {
                throw new RuntimeException('Código y nombre son obligatorios');
            }
            $stmt = $pdo->prepare("INSERT INTO entidad (id, nombre, estado) VALUES (?, ?, ?)");
            $stmt->execute([$codigo, $nombre, $estado]);
            header('Location: index.php?page=entidades&success=' . urlencode('Entidad creada'));
            exit;
        }

        if ($postAction === 'update') {
            $codigo = (int)($_POST['codigo'] ?? 0);
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $estado = !empty($_POST['estado']) ? 1 : 0;
            if ($codigo <= 0 || $nombre === '') {
                throw new RuntimeException('Código y nombre son obligatorios');
            }
            $stmt = $pdo->prepare("UPDATE entidad SET nombre = ?, estado = ? WHERE id = ?");
            $stmt->execute([$nombre, $estado, $codigo]);
            header('Location: index.php?page=entidades&success=' . urlencode('Entidad actualizada'));
            exit;
        }

        if ($postAction === 'delete') {
            $codigo = (int)($_POST['codigo'] ?? 0);
            if ($codigo <= 0) {
                throw new RuntimeException('Código inválido');
            }
            $stmtOrg = $pdo->prepare("SELECT COUNT(*) FROM organizaciones WHERE entidad = ?");
            $stmtOrg->execute([$codigo]);
            $usada = (int)$stmtOrg->fetchColumn() > 0;
            if ($usada) {
                throw new RuntimeException('No se puede eliminar: la entidad tiene organizaciones asociadas');
            }
            $stmt = $pdo->prepare("DELETE FROM entidad WHERE id = ?");
            $stmt->execute([$codigo]);
            header('Location: index.php?page=entidades&success=' . urlencode('Entidad eliminada'));
            exit;
        }
    } catch (Throwable $e) {
        header('Location: index.php?page=entidades&error=' . urlencode($e->getMessage()));
        exit;
    }
}

if ($action === 'detail') {
    include_once __DIR__ . '/entidades/detail.php';
    return;
}

include_once __DIR__ . '/admin_general/entidades/actions/index.php';
