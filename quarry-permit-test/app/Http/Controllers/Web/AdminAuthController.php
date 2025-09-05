<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
{
    public function showLogin()
    {
        return view('admin.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = DB::table('users')->where('username', $request->input('username'))->first();
        if (!$user || !Hash::check($request->input('password'), $user->password) || ($user->role ?? 'user') !== 'admin') {
            return back()->withErrors(['username' => 'Invalid credentials or not an admin'])->withInput();
        }

        // Set session for web middleware
        $request->session()->put('user_id', $user->id);
        $request->session()->put('username', $user->username);
        $request->session()->put('role', $user->role);

        return redirect('/home/admin');
    }

    public function logout(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/');
    }
}

