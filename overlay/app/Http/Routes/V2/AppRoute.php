<?php

namespace App\Http\Routes\V2;

use Illuminate\Contracts\Routing\Registrar;

class AppRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'app'
        ], function ($router) {
            $router->get('/bootstrap', 'V2\\App\\AppController@bootstrap');
            $router->get('/capabilities', 'V2\\App\\AppController@capabilities');
            $router->get('/client/version', 'V2\\App\\AppController@version');
            $router->get('/client/debug', 'V2\\App\\AppController@clientDebug');
            $router->get('/disaster-recovery', 'V2\\App\\AppController@disasterRecovery');
            $router->get('/notices', 'V2\\App\\NoticeController@index');
            $router->get('/notices/{id}', 'V2\\App\\NoticeController@show');
            $router->get('/plans', 'V2\\App\\PlanController@index');
            $router->get('/plans/{id}', 'V2\\App\\PlanController@show');

            $router->group([
                'prefix' => 'auth'
            ], function ($router) {
                $router->post('/register', 'V2\\App\\AuthController@register');
                $router->post('/login', 'V2\\App\\AuthController@login');
                $router->post('/send-email-code', 'V2\\App\\AuthController@sendEmailCode');
            });

            $router->group([
                'middleware' => 'app.user'
            ], function ($router) {
                $router->get('/auth/session', 'V2\\App\\AuthController@session');
                $router->post('/auth/logout', 'V2\\App\\AuthController@logout');

                $router->get('/client/config', 'V2\\App\\AppController@clientConfig');
                $router->get('/user/info', 'V2\\App\\UserController@info');
                $router->get('/nodes/manifest', 'V2\\App\\NodeController@manifest');
                $router->get('/nodes/list', 'V2\\App\\NodeController@index');

                $router->get('/orders', 'V2\\App\\OrderController@index');
                $router->get('/orders/payment-methods', 'V2\\App\\OrderController@paymentMethods');
                $router->post('/orders/create', 'V2\\App\\OrderController@create');
                $router->post('/orders/renew', 'V2\\App\\OrderController@renew');
                $router->post('/orders/checkout', 'V2\\App\\OrderController@checkout');
                $router->get('/orders/{tradeNo}', 'V2\\App\\OrderController@show');
                $router->get('/orders/{tradeNo}/status', 'V2\\App\\OrderController@status');
                $router->post('/orders/{tradeNo}/cancel', 'V2\\App\\OrderController@cancel');

                $router->post('/diagnostics/report', 'V2\\App\\AppController@diagnosticsReport');
            });
        });
    }
}
