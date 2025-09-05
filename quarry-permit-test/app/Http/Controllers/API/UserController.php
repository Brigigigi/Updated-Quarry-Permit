<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->only(['username', 'password', 'role']);
        if (!$data['username'] || !$data['password'] || !$data['role']) {
            return response()->json(['message' => 'All fields are required'], 400);
        }

        // Determine unique identifier column
        $identifierColumn = Schema::hasColumn('users', 'username')
            ? 'username'
            : (Schema::hasColumn('users', 'email') ? 'email' : (Schema::hasColumn('users', 'name') ? 'name' : null));

        if ($identifierColumn) {
            $exists = DB::table('users')->where($identifierColumn, $data['username'])->exists();
            if ($exists) {
                return response()->json(['message' => 'Username already exists'], 400);
            }
        }

        $insert = [
            'password' => Hash::make($data['password']),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('users', 'username')) {
            $insert['username'] = $data['username'];
        }
        if (Schema::hasColumn('users', 'name')) {
            $insert['name'] = $data['username'];
        }
        if (Schema::hasColumn('users', 'email')) {
            // Ensure uniqueness; adjust domain if needed
            $insert['email'] = $data['username'].'@example.local';
        }
        if (Schema::hasColumn('users', 'role')) {
            $insert['role'] = $data['role'];
        }

        DB::table('users')->insert($insert);

        return response()->json(['message' => 'Registered successfully!']);
    }

    public function login(Request $request)
    {
        $data = $request->only(['username', 'password']);
        if (!$data['username'] || !$data['password']) {
            return response()->json(['message' => 'All fields are required'], 400);
        }

        // Resolve lookup column for login
        $lookupColumn = Schema::hasColumn('users', 'username')
            ? 'username'
            : (Schema::hasColumn('users', 'email') ? 'email' : (Schema::hasColumn('users', 'name') ? 'name' : null));

        if (!$lookupColumn) {
            return response()->json(['message' => 'User table has no suitable identifier column'], 500);
        }

        $lookupValue = $lookupColumn === 'email' ? ($data['username'].'@example.local') : $data['username'];
        $user = DB::table('users')->where($lookupColumn, $lookupValue)->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid username or password'], 401);
        }

        $role = property_exists($user, 'role') ? $user->role : (isset($user->role) ? $user->role : 'user');
        return response()->json(['message' => 'Login successful', 'role' => $role]);
    }

    public function logout()
    {
        // Stateless API: nothing to do server-side
        return response()->json(['message' => 'Logged out']);
    }
}
