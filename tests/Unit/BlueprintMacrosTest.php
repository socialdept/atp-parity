<?php

namespace SocialDept\AtpParity\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SocialDept\AtpParity\Tests\TestCase;

class BlueprintMacrosTest extends TestCase
{
    public function test_atp_macro_adds_uri_cid_and_synced_at_columns(): void
    {
        Schema::create('test_atp_macro', function (Blueprint $table) {
            $table->id();
            $table->atp();
        });

        $this->assertTrue(Schema::hasColumn('test_atp_macro', 'atp_uri'));
        $this->assertTrue(Schema::hasColumn('test_atp_macro', 'atp_cid'));
        $this->assertTrue(Schema::hasColumn('test_atp_macro', 'atp_synced_at'));

        Schema::drop('test_atp_macro');
    }

    public function test_atp_reference_macro_adds_reference_uri_and_cid_columns(): void
    {
        Schema::create('test_atp_ref_macro', function (Blueprint $table) {
            $table->id();
            $table->atpReference();
        });

        $this->assertTrue(Schema::hasColumn('test_atp_ref_macro', 'atp_reference_uri'));
        $this->assertTrue(Schema::hasColumn('test_atp_ref_macro', 'atp_reference_cid'));

        Schema::drop('test_atp_ref_macro');
    }

    public function test_atp_reference_macro_without_cid_only_adds_uri(): void
    {
        Schema::create('test_atp_ref_no_cid', function (Blueprint $table) {
            $table->id();
            $table->atpReference(includeCid: false);
        });

        $this->assertTrue(Schema::hasColumn('test_atp_ref_no_cid', 'atp_reference_uri'));
        $this->assertFalse(Schema::hasColumn('test_atp_ref_no_cid', 'atp_reference_cid'));

        Schema::drop('test_atp_ref_no_cid');
    }

    public function test_drop_atp_macro_removes_all_atp_columns(): void
    {
        Schema::create('test_drop_atp', function (Blueprint $table) {
            $table->id();
            $table->atp();
        });

        $this->assertTrue(Schema::hasColumn('test_drop_atp', 'atp_uri'));

        Schema::table('test_drop_atp', function (Blueprint $table) {
            $table->dropAtp();
        });

        $this->assertFalse(Schema::hasColumn('test_drop_atp', 'atp_uri'));
        $this->assertFalse(Schema::hasColumn('test_drop_atp', 'atp_cid'));
        $this->assertFalse(Schema::hasColumn('test_drop_atp', 'atp_synced_at'));

        Schema::drop('test_drop_atp');
    }

    public function test_drop_atp_reference_macro_removes_reference_columns(): void
    {
        Schema::create('test_drop_atp_ref', function (Blueprint $table) {
            $table->id();
            $table->atpReference();
        });

        $this->assertTrue(Schema::hasColumn('test_drop_atp_ref', 'atp_reference_uri'));

        Schema::table('test_drop_atp_ref', function (Blueprint $table) {
            $table->dropAtpReference();
        });

        $this->assertFalse(Schema::hasColumn('test_drop_atp_ref', 'atp_reference_uri'));
        $this->assertFalse(Schema::hasColumn('test_drop_atp_ref', 'atp_reference_cid'));

        Schema::drop('test_drop_atp_ref');
    }

    public function test_drop_atp_reference_macro_without_cid_only_removes_uri(): void
    {
        Schema::create('test_drop_ref_no_cid', function (Blueprint $table) {
            $table->id();
            $table->atpReference(includeCid: false);
        });

        Schema::table('test_drop_ref_no_cid', function (Blueprint $table) {
            $table->dropAtpReference(includeCid: false);
        });

        $this->assertFalse(Schema::hasColumn('test_drop_ref_no_cid', 'atp_reference_uri'));

        Schema::drop('test_drop_ref_no_cid');
    }
}
