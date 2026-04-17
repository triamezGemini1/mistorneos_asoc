<?php

namespace Lib\Service;

use Lib\Repository\UserRepository;

/**
 * AuthService - Lógica de negocio de autenticación
 */
class AuthService
{
    private UserRepository $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * Intenta autenticar un usuario
     * 
     * @return array|null Usuario si éxito, null si falla
     */
    public function authenticate(string $username, string $password): ?array
    {
        $user = $this->userRepository->findByUsername($username);
        
        if (!$user) {
            return null;
        }

        // Verificar contraseña
        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }

        // No devolver el hash de contraseña
        unset($user['password_hash']);
        
        return $user;
    }

    /**
     * Verifica si las credenciales son las por defecto
     */
    public function isUsingDefaultCredentials(string $username, string $password): bool
    {
        $defaults = [
            ['username' => 'admin', 'password' => 'admin123'],
            ['username' => 'admin', 'password' => 'password'],
            ['username' => 'admin', 'password' => '123456'],
        ];

        foreach ($defaults as $cred) {
            if (strtolower($username) === strtolower($cred['username']) 
                && $password === $cred['password']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cambia la contraseña de un usuario
     */
    public function changePassword(int $userId, string $newPassword): bool
    {
        // Validar fortaleza de contraseña
        if (!$this->isPasswordStrong($newPassword)) {
            return false;
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        return $this->userRepository->updatePassword($userId, $hash);
    }

    /**
     * Verifica si una contraseña es suficientemente fuerte
     */
    public function isPasswordStrong(string $password): bool
    {
        // Mínimo 8 caracteres
        if (strlen($password) < 8) {
            return false;
        }

        // No debe ser una contraseña común
        $weak = ['password', '12345678', 'admin123', 'password123', 'qwerty123', 
                 'letmein', 'welcome', 'monkey', 'dragon', 'master'];
        
        if (in_array(strtolower($password), $weak)) {
            return false;
        }

        return true;
    }

    /**
     * Genera token para reset de contraseña
     */
    public function generatePasswordResetToken(string $email): ?string
    {
        $user = $this->userRepository->findByEmail($email);
        
        if (!$user) {
            return null;
        }

        // Generar token seguro
        $token = bin2hex(random_bytes(32));
        
        // TODO: Guardar token en BD con expiración
        // Por ahora solo retornamos el token
        
        return $token;
    }

    /**
     * Verifica si un usuario tiene un rol específico
     */
    public function hasRole(array $user, array $roles): bool
    {
        return in_array($user['role'] ?? '', $roles, true);
    }

    /**
     * Verifica si el usuario puede acceder a un torneo
     */
    public function canAccessTournament(array $user, int $tournamentId, \PDO $pdo): bool
    {
        // Admin general puede todo (incl. cuenta admin_general con rol simulado)
        if (class_exists('Auth') && \Auth::isAdminGeneralUser($user)) {
            return true;
        }

        // Admin torneo/club solo su club
        if (in_array($user['role'], ['admin_torneo', 'admin_club'])) {
            $userClubId = $user['club_id'] ?? null;
            
            if (!$userClubId) {
                return false;
            }

            $stmt = $pdo->prepare("SELECT club_responsable FROM tournaments WHERE id = ?");
            $stmt->execute([$tournamentId]);
            $tournament = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $tournament && $tournament['club_responsable'] == $userClubId;
        }

        return false;
    }
}


