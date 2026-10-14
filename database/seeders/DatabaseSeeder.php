<?php

namespace Database\Seeders;

use App\Models\AuthSecurityEvent;
use App\Models\Project;
use App\Models\SchemaArtifact;
use App\Models\SchemaImport;
use App\Models\SchemaImportDiagnostic;
use App\Models\User;
use App\Services\LrgSchemaNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with realistic test data.
     * Genera al menos 20 registros distribuidos en varias tablas.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \LogicException('Demo seed data must never be loaded in production.');
        }

        // ----------------------------------------------------------------
        // 1. Usuario administrador / demo conocido
        // ----------------------------------------------------------------
        $admin = User::updateOrCreate(['email' => 'admin@lrg.local'], [
            'name' => 'Admin LRG',
            'password' => 'Admin123456!',
            'email_verified_at' => now(),
        ]);

        // ----------------------------------------------------------------
        // 2. Usuario desarrollador de prueba
        // ----------------------------------------------------------------
        $dev = User::updateOrCreate(['email' => 'dev@lrg.local'], [
            'name' => 'Dev Usuario',
            'password' => 'Dev123456789!',
            'email_verified_at' => now(),
        ]);

        // ----------------------------------------------------------------
        // 3. Usuario sin verificar (para probar restricciones)
        // ----------------------------------------------------------------
        User::updateOrCreate(['email' => 'unverified@lrg.local'], [
            'name' => 'Usuario Sin Verificar',
            'password' => 'Unverified123!',
            'email_verified_at' => null,
        ]);

        // ----------------------------------------------------------------
        // 4. Usuarios adicionales (5 más)
        // ----------------------------------------------------------------
        $extraUsers = collect();
        foreach (range(1, 5) as $number) {
            $extraUsers->push(User::updateOrCreate(
                ['email' => "demo{$number}@lrg.local"],
                [
                    'name' => "Usuario Demo {$number}",
                    'password' => 'DemoUsuario123!',
                    'email_verified_at' => now(),
                ]
            ));
        }
        $allUsers = collect([$admin, $dev])->merge($extraUsers);

        // ----------------------------------------------------------------
        // 5. Proyectos del admin (4 proyectos)
        // ----------------------------------------------------------------
        $projectsAdmin = $this->createProjectsForUser($admin, [
            ['name' => 'Sistema de Reservas de Canchas', 'status' => 'active'],
            ['name' => 'Portal de Clientes CRM',         'status' => 'active'],
            ['name' => 'E-Commerce Tienda Online',       'status' => 'active'],
            ['name' => 'App Legado 2020',                'status' => 'archived'],
            ['name' => 'Mesa de Ayuda',                  'status' => 'active'],
            ['name' => 'Catálogo de Productos',          'status' => 'active'],
            ['name' => 'Agenda Médica',                  'status' => 'active'],
            ['name' => 'Control de Flota',               'status' => 'active'],
            ['name' => 'Biblioteca Digital',             'status' => 'active'],
            ['name' => 'Portal de Proveedores',          'status' => 'active'],
            ['name' => 'App de Encuestas',               'status' => 'archived'],
        ]);

        // ----------------------------------------------------------------
        // 6. Proyectos del dev (3 proyectos)
        // ----------------------------------------------------------------
        $projectsDev = $this->createProjectsForUser($dev, [
            ['name' => 'Gestión de Inventario Almacén', 'status' => 'active'],
            ['name' => 'Sistema de Facturación PYMEs',  'status' => 'active'],
            ['name' => 'Portal RR.HH. Empleados',       'status' => 'active'],
        ]);

        // ----------------------------------------------------------------
        // 7. Proyectos para usuarios extra (6 proyectos, 1-2 por usuario)
        // ----------------------------------------------------------------
        foreach ($extraUsers->take(3) as $i => $user) {
            $this->createProjectsForUser($user, [
                ['name' => "Proyecto Alpha {$user->id}", 'status' => 'active'],
                ['name' => "Proyecto Beta {$user->id}",  'status' => 'active'],
            ]);
        }

        // ----------------------------------------------------------------
        // 8. Importaciones con artefactos para el proyecto "Reservas" del admin
        // ----------------------------------------------------------------
        $reservasProject = $projectsAdmin->first();
        $this->seedFullImportCycle(
            $reservasProject,
            $admin,
            'reservas_v1.sql',
            $this->getReservasSQL(),
            1
        );

        // Segunda versión del mismo proyecto
        $this->seedFullImportCycle(
            $reservasProject,
            $admin,
            'reservas_v2.sql',
            $this->getReservasV2SQL(),
            2
        );

        // ----------------------------------------------------------------
        // 9. Importación completada para proyecto CRM
        // ----------------------------------------------------------------
        $crmProject = $projectsAdmin->get(1);
        $this->seedFullImportCycle($crmProject, $admin, 'crm_schema.sql', $this->getCrmSQL(), 1);

        // ----------------------------------------------------------------
        // 10. Importación con errores (fallida) para proyecto E-Commerce
        // ----------------------------------------------------------------
        $ecomProject = $projectsAdmin->get(2);
        $this->seedFailedImport($ecomProject, $admin, 'ecommerce_broken.sql');

        // ----------------------------------------------------------------
        // 11. Importación completada para proyecto Inventario del dev
        // ----------------------------------------------------------------
        $inventarioProject = $projectsDev->first();
        $this->seedFullImportCycle(
            $inventarioProject,
            $dev,
            'inventario_schema.sql',
            $this->getInventarioSQL(),
            1
        );

        // ----------------------------------------------------------------
        // 12. Importación pendiente/en cola para Facturación del dev
        // ----------------------------------------------------------------
        $facturacionProject = $projectsDev->get(1);
        $pendingSql = $this->getInventarioSQL();
        $pendingKey = 'demo:pending:facturacion.sql';
        if (! SchemaImport::where('project_id', $facturacionProject->id)
            ->where('idempotency_key', $pendingKey)
            ->exists()) {
            $pendingPath = "schema_imports/{$facturacionProject->id}/".Str::uuid().'.sql';
            Storage::disk('local')->put($pendingPath, $pendingSql);

            SchemaImport::create([
                'project_id' => $facturacionProject->id,
                'requested_by' => $dev->id,
                'original_filename' => 'facturacion.sql',
                'storage_path' => $pendingPath,
                'file_sha256' => hash('sha256', $pendingSql),
                'file_size_bytes' => strlen($pendingSql),
                'dialect' => 'mysql',
                'status' => 'queued',
                'idempotency_key' => $pendingKey,
                'attempt_count' => 0,
                'parser_version' => null,
                'queued_at' => now()->subMinutes(2),
            ]);
        }

        foreach ($allUsers as $user) {
            if (! AuthSecurityEvent::where('user_id', $user->id)
                ->where('event_type', 'login_success')
                ->where('user_agent', 'LRG Seeder')
                ->exists()) {
                AuthSecurityEvent::create([
                    'event_type' => 'login_success',
                    'user_id' => $user->id,
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'LRG Seeder',
                    'occurred_at' => now()->subDays(rand(1, 20)),
                    'metadata' => ['seed' => true],
                ]);
            }
        }

        $this->command->info('Seeder completado — '.User::count().' usuarios, '.
            Project::count().' proyectos, '.
            SchemaImport::count().' importaciones, '.
            SchemaArtifact::count().' artefactos, '.
            SchemaImportDiagnostic::count().' diagnosticos, '.
            AuthSecurityEvent::count().' eventos de seguridad.');
    }

    // -----------------------------------------------------------------------
    // Helpers privados
    // -----------------------------------------------------------------------

    private function createProjectsForUser(User $user, array $definitions): Collection
    {
        $projects = collect();

        foreach ($definitions as $def) {
            $name = $def['name'];
            $projects->push(Project::firstOrCreate(
                ['owner_id' => $user->id, 'name' => $name],
                [
                    'slug' => Str::slug($name).'-'.$user->id,
                    'description' => "Proyecto de demostración: {$name}",
                    'status' => $def['status'] ?? 'active',
                ]
            ));
        }

        return $projects;
    }

    /**
     * Simula un ciclo completo de importación exitosa con artefacto publicado.
     */
    private function seedFullImportCycle(
        Project $project,
        User $user,
        string $filename,
        string $sqlContent,
        int $version
    ): void {
        if (SchemaArtifact::where('project_id', $project->id)->where('version', $version)->exists()) {
            return;
        }

        $sha256 = hash('sha256', $sqlContent);
        $idempotencyKey = 'demo:'.$filename;
        $import = SchemaImport::where('project_id', $project->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $import) {
            $storagePath = "schema_imports/{$project->id}/".Str::uuid().'.sql';
            Storage::disk('local')->put($storagePath, $sqlContent);
        } else {
            $storagePath = $import->storage_path;
            Storage::disk('local')->put($storagePath, $sqlContent);
        }

        $queuedAt = now()->subHours(rand(2, 48));
        $startedAt = (clone $queuedAt)->addSeconds(rand(2, 15));
        $finishedAt = (clone $startedAt)->addSeconds(rand(5, 60));

        $import ??= SchemaImport::create([
            'project_id' => $project->id,
            'requested_by' => $user->id,
            'original_filename' => $filename,
            'storage_path' => $storagePath,
            'file_sha256' => $sha256,
            'file_size_bytes' => strlen($sqlContent),
            'dialect' => 'mysql',
            'status' => 'completed',
            'idempotency_key' => $idempotencyKey,
            'attempt_count' => 1,
            'parser_version' => '1.0.0',
            'queued_at' => $queuedAt,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);

        $normalized = app(LrgSchemaNormalizer::class)->normalize($sqlContent, 'mysql', $sha256);
        if (app(LrgSchemaNormalizer::class)->hasBlockingErrors($normalized['diagnostics'])) {
            throw new \LogicException("Demo schema {$filename} cannot be normalized.");
        }
        $normalizedDoc = $normalized['document'];

        $import->update([
            'status' => 'completed',
            'error_code' => null,
            'error_summary' => null,
            'finished_at' => $finishedAt,
        ]);
        $import->diagnostics()->delete();

        foreach ($normalized['diagnostics'] as $diagnostic) {
            SchemaImportDiagnostic::create([
                'import_id' => $import->id,
                'severity' => $diagnostic['severity'] ?? 'info',
                'code' => $diagnostic['code'] ?? 'UNKNOWN',
                'message' => $diagnostic['message'] ?? '',
                'source_location' => $diagnostic['source_location'] ?? null,
                'created_at' => $finishedAt,
            ]);
        }

        $docJson = json_encode($normalizedDoc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $artifactSha256 = hash('sha256', $docJson);

        SchemaArtifact::create([
            'project_id' => $project->id,
            'import_id' => $import->id,
            'version' => $version,
            'format_name' => 'LRG-Schema',
            'format_version' => '1.0',
            'schema_document' => $normalizedDoc,
            'artifact_sha256' => $artifactSha256,
            'parser_version' => '1.0.0',
            'created_by' => $user->id,
            'created_at' => $finishedAt,
        ]);
    }

    private function seedFailedImport(Project $project, User $user, string $filename): void
    {
        $idempotencyKey = 'demo:'.$filename;
        if (SchemaImport::where('project_id', $project->id)->where('idempotency_key', $idempotencyKey)->exists()) {
            return;
        }

        $sqlContent = 'CREAT TABLE broken (id INT);';
        $storagePath = "schema_imports/{$project->id}/".Str::uuid().'.sql';
        Storage::disk('local')->put($storagePath, $sqlContent);
        $sha256 = hash('sha256', $sqlContent);
        $normalized = app(LrgSchemaNormalizer::class)->normalize($sqlContent, 'mysql', $sha256);
        $finishedAt = now()->subHours(5)->addSeconds(8);

        $import = SchemaImport::create([
            'project_id' => $project->id,
            'requested_by' => $user->id,
            'original_filename' => $filename,
            'storage_path' => $storagePath,
            'file_sha256' => $sha256,
            'file_size_bytes' => strlen($sqlContent),
            'dialect' => 'mysql',
            'status' => 'failed',
            'idempotency_key' => $idempotencyKey,
            'attempt_count' => 2,
            'parser_version' => '1.0.0',
            'error_code' => $normalized['diagnostics'][0]['code'] ?? 'VALIDATION_ERROR',
            'error_summary' => $normalized['diagnostics'][0]['message'] ?? 'Schema validation failed.',
            'queued_at' => now()->subHours(5),
            'started_at' => now()->subHours(5)->addSeconds(3),
            'finished_at' => $finishedAt,
        ]);

        foreach ($normalized['diagnostics'] as $diagnostic) {
            SchemaImportDiagnostic::create([
                'import_id' => $import->id,
                'severity' => $diagnostic['severity'] ?? 'error',
                'code' => $diagnostic['code'] ?? 'VALIDATION_ERROR',
                'message' => $diagnostic['message'] ?? 'Schema validation failed.',
                'source_location' => $diagnostic['source_location'] ?? null,
                'created_at' => $finishedAt,
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // SQL de muestra para cada dominio
    // -----------------------------------------------------------------------

    private function getReservasSQL(): string
    {
        return <<<'SQL'
CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(255) NOT NULL,
    email_verified_at TIMESTAMP NULL DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
);

CREATE TABLE courts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    sport VARCHAR(60) NOT NULL,
    indoor TINYINT(1) NOT NULL DEFAULT 0,
    hourly_rate DECIMAL(8,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'available',
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id)
);

CREATE TABLE reservations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    court_id BIGINT UNSIGNED NOT NULL,
    reserved_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_reservations_user (user_id),
    KEY idx_reservations_court_date (court_id, reserved_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (court_id) REFERENCES courts(id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE TABLE payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    method VARCHAR(30) NOT NULL DEFAULT 'card',
    transaction_id VARCHAR(100) NULL,
    paid_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE
);
SQL;
    }

    private function getReservasV2SQL(): string
    {
        return <<<'SQL'
CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(20) NULL,
    email_verified_at TIMESTAMP NULL DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
);

CREATE TABLE sports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    max_players TINYINT UNSIGNED NOT NULL DEFAULT 2,
    PRIMARY KEY (id)
);

CREATE TABLE courts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sport_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    indoor TINYINT(1) NOT NULL DEFAULT 0,
    hourly_rate DECIMAL(8,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'available',
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    FOREIGN KEY (sport_id) REFERENCES sports(id) ON DELETE RESTRICT
);

CREATE TABLE reservations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    court_id BIGINT UNSIGNED NOT NULL,
    reserved_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (court_id) REFERENCES courts(id) ON DELETE RESTRICT
);
SQL;
    }

    private function getCrmSQL(): string
    {
        return <<<'SQL'
CREATE TABLE companies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(200) NOT NULL,
    tax_id VARCHAR(30) NULL,
    sector VARCHAR(80) NULL,
    website VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id)
);

CREATE TABLE contacts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NULL,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(30) NULL,
    position VARCHAR(100) NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
);

CREATE TABLE deals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    value DECIMAL(12,2) NULL,
    stage VARCHAR(50) NOT NULL DEFAULT 'lead',
    expected_close_date DATE NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
);

CREATE TABLE activities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    deal_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(40) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    notes TEXT NULL,
    occurred_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE CASCADE
);
SQL;
    }

    private function getInventarioSQL(): string
    {
        return <<<'SQL'
CREATE TABLE categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
);

CREATE TABLE suppliers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(200) NOT NULL,
    contact_email VARCHAR(255) NULL,
    phone VARCHAR(30) NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id)
);

CREATE TABLE products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id BIGINT UNSIGNED NOT NULL,
    supplier_id BIGINT UNSIGNED NULL,
    sku VARCHAR(60) NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    stock_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    min_stock INT UNSIGNED NOT NULL DEFAULT 5,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_sku (sku),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
);

CREATE TABLE stock_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(20) NOT NULL,
    quantity INT NOT NULL,
    reference VARCHAR(100) NULL,
    notes TEXT NULL,
    moved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);
SQL;
    }
}
