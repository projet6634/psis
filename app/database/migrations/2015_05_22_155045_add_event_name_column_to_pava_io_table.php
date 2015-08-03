<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class AddEventNameColumnToPavaIoTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('eq_pava_io', function(Blueprint $table) {
			$table->increments('id');
			$table->integer('node_id');
			$table->date('date');
			$table->string('event_name');
			$table->string('sort');
			$table->float('amount');
			$table->timestamps();
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::create('eq_pava_io', function(Blueprint $table) {
			$table->increments('id');
			$table->integer('node_id');
			$table->date('date');
			$table->string('sort');
			$table->float('amount');
			$table->timestamps();
		});
	}

}