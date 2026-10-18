<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'portal:admin';

    protected $description = 'Create the first admin interactively';

    public function handle(): int
    {
        $name = $this->ask('Admin name');
        $email = $this->ask('Admin email');
        $password = $this->secret('Password (minimum 12 characters)');
        $v = Validator::make(compact('name', 'email', 'password'), ['name' => 'required|string|max:255', 'email' => 'required|email|unique:users,email', 'password' => 'required|string|min:12']);
        if ($v->fails()) {
            $this->error($v->errors()->first());

            return 1;
        } $u = new User;
        $u->name = $name;
        $u->email = $email;
        $u->password = Hash::make($password);
        $u->role = 'admin';
        $u->save();
        $this->info('Admin created. Sign in at /login.');

        return 0;
    }
}
