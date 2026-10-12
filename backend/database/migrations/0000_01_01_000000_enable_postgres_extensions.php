<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enables the PostgreSQL extensions the platform depends on.
 *
 * - postgis: geography(Point, 4326) columns, GiST indexes, ST_DWithin / KNN queries.
 * - pg_trgm: trigram GIN indexes used for fuzzy beneficiary name matching.
 *
 * In managed environments where the application role is not a superuser, a DBA
 * must create these extensions once; IF NOT EXISTS makes this migration a no-op
 * in that case.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    /**
     * Extensions are intentionally not dropped: they may be owned by a DBA role,
     * and dropping postgis would cascade to spatial_ref_sys and any other
     * spatial objects in the database.
     */
    public function down(): void
    {
        //
    }
};
