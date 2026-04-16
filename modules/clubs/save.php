<?php

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/admin_general_auth.php';

requireAdminGeneral();
CSRF::validate();

try {
    // Validar campos requeridos
    if (empty($_POST['nombre'])) {
        throw new Exception('El nombre del club es requerido');
    }

    $current_user = Auth::user();

    // Organización y entidad obligatorias (solo admin_general puede crear clubes)
    $organizacion_id = !empty($_POST['organizacion_id']) ? (int)$_POST['organizacion_id'] : null;
    if (!$organizacion_id) {
        throw new Exception('Debe seleccionar una organización. Todo club debe pertenecer a una organización con entidad definida.');
    }
    $entidad = 0;

    if ($organizacion_id) {
        $stmt = DB::pdo()->prepare("SELECT entidad FROM organizaciones WHERE id = ? AND estatus = 1");
        $stmt->execute([$organizacion_id]);
        $entidad = (int)$stmt->fetchColumn();
    }
    if ($entidad <= 0) {
        throw new Exception('La organización seleccionada no tiene entidad definida. No se puede registrar un club sin entidad.');
    }
    
    // Preparar datos (solo campos que existen en BD)
    $nombre = trim($_POST['nombre']);
    $direccion = trim($_POST['direccion'] ?? '');
    $delegado = trim($_POST['delegado'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $estatus = (int)($_POST['estatus'] ?? 1);
    $permite_inscripcion_linea = isset($_POST['permite_inscripcion_linea']) ? 1 : 0;
    
    // Manejar upload de logo (simplificado)
    $logo = null;
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $extension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        
        if (!in_array($extension, $allowed)) {
            throw new Exception('Solo se permiten imágenes JPG, PNG o GIF');
        }
        
        if ($_FILES['logo']['size'] > 5 * 1024 * 1024) {
            throw new Exception('El logo no puede superar 5MB');
        }
        
        // Crear directorio si no existe
        $upload_dir = __DIR__ . '/../../upload/logos';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // Nombre único para el archivo
        $logo_name = 'logo_' . time() . '_' . uniqid() . '.' . $extension;
        $logo_path = $upload_dir . '/' . $logo_name;
        
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $logo_path)) {
            $logo = 'upload/logos/' . $logo_name;
        } else {
            throw new Exception('Error al subir el logo');
        }
    }
    
    // Insertar en la base de datos (cod_org y entidad obligatorios)
    $cols = ['nombre', 'direccion', 'delegado', 'telefono', 'email', 'logo', 'estatus', 'cod_org'];
    $vals = [':nombre', ':direccion', ':delegado', ':telefono', ':email', ':logo', ':estatus', ':cod_org'];
    try {
        $chkEnt = DB::pdo()->query("SHOW COLUMNS FROM clubes LIKE 'entidad'");
        if ($chkEnt && $chkEnt->rowCount() > 0) {
            $cols[] = 'entidad';
            $vals[] = ':entidad';
        }
        $chk = DB::pdo()->query("SHOW COLUMNS FROM clubes LIKE 'permite_inscripcion_linea'");
        if ($chk && $chk->rowCount() > 0) {
            $cols[] = 'permite_inscripcion_linea';
            $vals[] = ':permite_inscripcion_linea';
        }
    } catch (Exception $e) { /* columna no existe */ }
    $stmt = DB::pdo()->prepare("INSERT INTO clubes (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")");
    $params = [
        ':nombre' => $nombre,
        ':direccion' => $direccion,
        ':delegado' => $delegado,
        ':telefono' => $telefono,
        ':email' => $email ?: null,
        ':logo' => $logo,
        ':estatus' => $estatus,
        ':cod_org' => $organizacion_id
    ];
    if (in_array('entidad', $cols)) {
        $params[':entidad'] = $entidad;
    }
    if (in_array('permite_inscripcion_linea', $cols)) {
        $params[':permite_inscripcion_linea'] = $permite_inscripcion_linea;
    }
    $stmt->execute($params);
    
    // Obtener el ID del club recién creado
    $club_id = (int)DB::pdo()->lastInsertId();
    
    // Generar PDF de invitación automáticamente
    try {
        require_once __DIR__ . '/../../lib/InvitationPDFGenerator.php';
        $pdf_result = InvitationPDFGenerator::generateClubInvitationPDF($club_id);
        if ($pdf_result['success']) {
            error_log("PDF de invitación generado para club {$club_id}: " . $pdf_result['pdf_path']);
        } else {
            error_log("Error generando PDF de invitación para club {$club_id}: " . ($pdf_result['error'] ?? 'Error desconocido'));
        }
    } catch (Exception $e) {
        error_log("Excepción al generar PDF de invitación para club {$club_id}: " . $e->getMessage());
        // No fallar la creación del club si falla el PDF
    }
    
    // Redirigir con éxito
    header('Location: index.php?page=clubs&success=' . urlencode('Club creado exitosamente'));
    exit;
    
} catch (Exception $e) {
    // Redirigir con error
    header('Location: index.php?page=clubs&action=new&error=' . urlencode($e->getMessage()));
    exit;
}
