<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApiDocLoginController extends Controller
{
    public function showLoginForm()
    {
        return view('api-doc.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            if (!auth()->user()->can('api-doc.read.all')) {
                Auth::logout();
                return back()->withErrors([
                    'email' => 'No tienes permiso para acceder a la documentación de la API.',
                ]);
            }

            return redirect()->intended(route('scramble.docs.ui'));
        }

        return back()->withErrors([
            'email' => 'Las credenciales proporcionadas no son correctas.',
        ])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('api-doc.login');
    }
}
