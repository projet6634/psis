<?php

/*
|--------------------------------------------------------------------------
| Application & Route Filters
|--------------------------------------------------------------------------
|
| Below you will find the "before" and "after" events for the application
| which may be used to do any work before or after a request into your
| application. Here you may also register your custom route filters.
|
*/

App::before(function($request)
{
});


App::after(function($request, $response)
{
});

App::error(function($e, $code){

	if (Request::ajax()) {

		return Response::make($e->getMessage(), $code);
	} else {

	}
});

/*
|--------------------------------------------------------------------------
| Authentication Filters
|--------------------------------------------------------------------------
|
| The following filters are used to verify that the user of the current
| session is logged into this application. The "basic" filter easily
| integrates HTTP Basic authentication for quick, simple checking.
|
*/

Route::filter('auth', function()
{
	if (!Sentry::check()) return Redirect::guest('login');
});

Route::filter('ajax', function()
{
	if (!Request::ajax()) return App::abort(400);
});

/*
|--------------------------------------------------------------------------
| Guest Filter
|--------------------------------------------------------------------------
|
| The "guest" filter is the counterpart of the authentication filters as
| it simply checks that the current user is not logged in. A redirect
| response will be issued if they are, which you may freely change.
|
*/

Route::filter('guest', function()
{
	if (Sentry::check()) return Redirect::to('/');
});

/*
|--------------------------------------------------------------------------
| CSRF Protection Filter
|--------------------------------------------------------------------------
|
| The CSRF filter is responsible for protecting your application against
| cross-site request forgery attacks. If this special token in a user
| session does not match the one given in this request, we'll bail.
|
*/

Route::filter('csrf', function()
{
	if (Session::token() != Input::get('_token'))
	{
		throw new Illuminate\Session\TokenMismatchException;
	}
});

/**
 * View Composers
 */

View::composer('parts.notification', 'NotificationComposer');
View::composer('widget.sidebar-profile', 'SidebarProfileComposer');
View::composer('layouts.master', 'MenuComposer');

Route::filter('permission', function($route, $request, $permission) {

	if (!Sentry::getUser()->hasAccess($permission)) {
		return App::abort(403);
	}
});

Route::filter('menu', function(){
	$service = new MenuService;
	$service->activateMenuByUrl(Request::path());
});


Route::filter('migrate', function() {
	// user migration filter from v1.0 to 2.0
	if (Sentry::check()) {
		$user = Sentry::getUser();
		$migrationDate = '2014-04-28';
		if (strtotime($user->created_at->toDateTimeString()) < strtotime($migrationDate)) {
			// if not migrated, redirect to migration form page
			if (DB::table('user_migrations')->where('user_id', '=', $user->id)->count() == 0) {
				return Redirect::to('/migrate');
			}
		}
	}
});