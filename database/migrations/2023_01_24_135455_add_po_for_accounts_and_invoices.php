<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('accounts', function($t)
        {
           $t->string('po')->nullable();
        });
        Schema::table('invoices', function($t)
        {
            $t->string('po')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('accounts', function($t)
        {
            $t->dropColumn('po');
        });
        Schema::table('invoices', function($t)
        {
            $t->dropColumn('po');
        });
    }
};
