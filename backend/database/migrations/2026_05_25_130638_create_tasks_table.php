<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up(): void
{
    Schema::create('tasks', function (Blueprint $table) {
        $table->id();

        $table->string('title');

        $table->text('description')->nullable();

        $table->string('category')->nullable();

        $table->dateTime('deadline')->nullable();

        $table->enum('priority', [
            'Critical',
            'High',
            'Medium',
            'Low'
        ])->default('Medium');

        $table->integer('progress')->default(0);

        $table->enum('status', [
            'todo',
            'progress',
            'completed'
        ])->default('todo');

        $table->timestamps();
    });
}
};
