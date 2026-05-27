public function up(): void
{
    Schema::create('todos', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->text('notes')->nullable();
        $table->enum('status', ['todo', 'inprogress', 'done'])->default('todo');
        $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
        $table->string('category')->default('General');
        $table->integer('progress')->default(0);
        $table->dateTime('deadline')->nullable();
        $table->boolean('is_completed')->default(false);
        $table->timestamps();
    });
}