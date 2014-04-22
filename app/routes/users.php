<?php 

Route::group(array('prefix'=>'user'), function(){
    Route::get('profile', array('uses'=>'UserController@displayProfile'));
    Route::get('profile_edit', 'UserController@displayProfileEdit');
    Route::post('contact_mod', 'UserController@contactMod');
    Route::post('general_mod', 'UserController@generalMod');
    Route::get('change_password', array('uses'=>'UserController@displayPasswordMod'));
    Route::get('delete', 'UserController@displayDropout');
});