<?php

namespace Lib\Service;

use Lib\Repository\TournamentRepository;
use Lib\Repository\ClubRepository;

/**
 * TournamentService - Lógica de negocio de torneos
 */
class TournamentService
{
    private TournamentRepository $tournamentRepository;
    private ?ClubRepository $clubRepository;

    public function __construct(
        TournamentRepository $tournamentRepository,
        ?ClubRepository $clubRepository = null
    ) {
        $this->tournamentRepository = $tournamentRepository;
        $this->clubRepository = $clubRepository;
    }

    /**
     * Obtiene torneos filtrados por rol de usuario
     */
    public function getTournamentsForUser(array $user, array $filters = []): array
    {
        // Admin general ve todo (incl. cuenta admin_general con rol simulado)
        if (class_exists('Auth') && \Auth::isAdminGeneralUser($user)) {
            return $this->tournamentRepository->findAll($filters);
        }

        // Admin torneo/club solo ve los de su club
        if (in_array($user['role'], ['admin_torneo', 'admin_club'])) {
            $filters['club_id'] = $user['club_id'] ?? 0;
            return $this->tournamentRepository->findAll($filters);
        }

        return [];
    }

    /**
     * Obtiene un torneo con validación de acceso
     */
    public function getTournamentForUser(int $tournamentId, array $user): ?array
    {
        $tournament = $this->tournamentRepository->findById($tournamentId);
        
        if (!$tournament) {
            return null;
        }

        // Verificar acceso
        if (!$this->canUserAccessTournament($user, $tournament)) {
            return null;
        }

        // Agregar estadísticas
        $tournament['stats'] = $this->tournamentRepository->getStats($tournamentId);
        
        return $tournament;
    }

    /**
     * Verifica si un usuario puede acceder a un torneo
     */
    public function canUserAccessTournament(array $user, array $tournament): bool
    {
        if (class_exists('Auth') && \Auth::isAdminGeneralUser($user)) {
            return true;
        }

        if (in_array($user['role'], ['admin_torneo', 'admin_club'])) {
            return ($tournament['club_responsable'] ?? null) == ($user['club_id'] ?? -1);
        }

        return false;
    }

    /**
     * Verifica si un usuario puede modificar un torneo
     */
    public function canUserModifyTournament(array $user, int $tournamentId): bool
    {
        // Admin general puede todo (incl. cuenta admin_general con rol simulado)
        if (class_exists('Auth') && \Auth::isAdminGeneralUser($user)) {
            return true;
        }

        $tournament = $this->tournamentRepository->findById($tournamentId);
        
        if (!$tournament) {
            return false;
        }

        // Debe ser de su club
        if (!$this->canUserAccessTournament($user, $tournament)) {
            return false;
        }

        // No debe haber pasado
        if ($this->tournamentRepository->isPast($tournamentId)) {
            return false;
        }

        return true;
    }

    /**
     * Crea un torneo con validación
     */
    public function createTournament(array $data, array $user): array
    {
        $errors = $this->validateTournamentData($data);
        
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        // Si no es cuenta admin_general, forzar club del usuario
        if (!(class_exists('Auth') && \Auth::isAdminGeneralUser($user))) {
            $data['club_responsable'] = $user['club_id'];
        }

        try {
            $id = $this->tournamentRepository->create($data);
            return ['success' => true, 'id' => $id];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ['general' => 'Error al crear torneo']];
        }
    }

    /**
     * Actualiza un torneo con validación
     */
    public function updateTournament(int $id, array $data, array $user): array
    {
        // Verificar permisos
        if (!$this->canUserModifyTournament($user, $id)) {
            return ['success' => false, 'errors' => ['permission' => 'No tienes permisos para modificar este torneo']];
        }

        $errors = $this->validateTournamentData($data, $id);
        
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        try {
            $this->tournamentRepository->update($id, $data);
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ['general' => 'Error al actualizar torneo']];
        }
    }

    /**
     * Elimina un torneo
     */
    public function deleteTournament(int $id, array $user): array
    {
        // Verificar permisos
        if (!$this->canUserModifyTournament($user, $id)) {
            return ['success' => false, 'error' => 'No tienes permisos para eliminar este torneo'];
        }

        try {
            $this->tournamentRepository->delete($id);
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Error al eliminar torneo'];
        }
    }

    /**
     * Valida datos de torneo
     */
    private function validateTournamentData(array $data, ?int $excludeId = null): array
    {
        $errors = [];

        if (empty($data['nombre'])) {
            $errors['nombre'] = 'El nombre es requerido';
        } elseif (strlen($data['nombre']) > 255) {
            $errors['nombre'] = 'El nombre es demasiado largo';
        }

        if (empty($data['fechator'])) {
            $errors['fechator'] = 'La fecha es requerida';
        } elseif (!strtotime($data['fechator'])) {
            $errors['fechator'] = 'Fecha inválida';
        }

        return $errors;
    }

    /**
     * Obtiene torneos próximos para dashboard
     */
    public function getUpcomingTournaments(int $limit = 5): array
    {
        return $this->tournamentRepository->findUpcoming($limit);
    }

    /**
     * Obtiene estadísticas generales
     */
    public function getDashboardStats(?int $clubId = null): array
    {
        $filters = $clubId ? ['club_id' => $clubId] : [];
        
        return [
            'total_tournaments' => $this->tournamentRepository->count($filters),
            'upcoming' => count($this->tournamentRepository->findUpcoming(100))
        ];
    }
}


