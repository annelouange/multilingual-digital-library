<?php

class TenantService
{
    public const DEFAULT_INSTITUTION_SLUG = 'rwanda-library-network';

    public static function defaultInstitution(): ?array
    {
        return self::findBySlug(self::DEFAULT_INSTITUTION_SLUG);
    }

    public static function findBySlug(string $slug): ?array
    {
        try {
            $stmt = Database::connection()->prepare(
                'SELECT * FROM institutions WHERE slug=:slug AND status <> "archived" LIMIT 1'
            );
            $stmt->execute([':slug' => $slug]);
            $institution = $stmt->fetch();
            return $institution ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function forUser(array $user): ?array
    {
        try {
            $stmt = Database::connection()->prepare(
                'SELECT i.*, iu.institution_role
                 FROM institution_users iu
                 JOIN institutions i ON i.id=iu.institution_id
                 WHERE iu.user_id=:user_id AND iu.status="active" AND i.status <> "archived"
                 ORDER BY iu.is_primary DESC, iu.created_at ASC
                 LIMIT 1'
            );
            $stmt->execute([':user_id' => $user['id']]);
            $institution = $stmt->fetch();
            if ($institution) {
                return $institution;
            }
        } catch (Throwable) {
            // The SaaS migration may not be applied yet. Fall back safely.
        }

        return self::defaultInstitution();
    }

    public static function institutionIdFor(?array $user): ?int
    {
        $institution = $user ? self::forUser($user) : self::defaultInstitution();
        return $institution ? (int)$institution['id'] : null;
    }

    public static function health(): array
    {
        $default = self::defaultInstitution();
        return [
            'status' => $default ? 'ready' : 'migration_pending',
            'default_institution' => $default ? [
                'id' => (int)$default['id'],
                'name' => $default['name'],
                'slug' => $default['slug'],
                'status' => $default['status'],
            ] : null,
        ];
    }
}
