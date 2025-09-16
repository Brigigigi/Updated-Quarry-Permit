#!/usr/bin/env python3
"""
Minimal Admin Creator for Laravel (SQLite)

Purpose: For a sysadmin to quickly add an admin user to this website.

Rules:
- Requires an existing admin (root admin) to authenticate first before creating another admin.

Behavior:
- Reads Laravel .env to detect bcrypt cost (BCRYPT_ROUNDS) and DB (SQLite).
- Prompts only for usernames/passwords.
- Hashes via PHP's password_hash (bcrypt, $2y$) to avoid Laravel runtime errors.

Usage:
  python admin_console.py

Note: Requires PHP CLI on PATH.
"""

import os
import sys
import sqlite3
import getpass
import subprocess
import argparse

ROOT = os.path.dirname(os.path.abspath(__file__))


def read_env() -> dict:
    env_path = os.path.join(ROOT, '.env')
    conf = {}
    if os.path.isfile(env_path):
        with open(env_path, 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith('#'):
                    continue
                if '=' in line:
                    k, v = line.split('=', 1)
                    conf[k.strip()] = v.strip().strip('"')
    return conf


def resolve_sqlite_path(conf: dict) -> str:
    # Default Laravel SQLite location
    return os.path.join(ROOT, 'database', 'database.sqlite')


def php_bcrypt_hash(password: str, cost: int = 12) -> str:
    """Generate a bcrypt $2y$ hash via PHP CLI for Laravel compatibility."""
    php = 'php'
    code = ("$p=$argv[1]; $c=(int)$argv[2]; echo password_hash($p, PASSWORD_BCRYPT, ['cost'=>$c]);")
    try:
        out = subprocess.check_output([php, '-r', code, password, str(cost)], text=True)
        h = out.strip()
        if not h.startswith('$2y$'):
            raise RuntimeError('PHP did not return a $2y$ bcrypt hash')
        return h
    except FileNotFoundError:
        raise RuntimeError('PHP CLI not found. Please install PHP and ensure "php" is on PATH.')
    except Exception as e:
        raise RuntimeError(f'Unable to hash password via PHP: {e}')


def php_password_verify(password: str, hashed: str) -> bool:
    php = 'php'
    code = "$p=$argv[1]; $h=$argv[2]; echo password_verify($p,$h)?'1':'0';"
    try:
        out = subprocess.check_output([php, '-r', code, password, hashed], text=True)
        return out.strip() == '1'
    except FileNotFoundError:
        raise RuntimeError('PHP CLI not found. Please install PHP and ensure "php" is on PATH.')
    except Exception:
        return False


def user_exists(conn: sqlite3.Connection, username: str) -> bool:
    cur = conn.execute('SELECT 1 FROM users WHERE username=? LIMIT 1', (username,))
    return cur.fetchone() is not None


def create_admin(conn: sqlite3.Connection, username: str, password_hash: str):
    conn.execute(
        'INSERT INTO users (username, password, role, created_at, updated_at) VALUES (?,?,?,?,?)',
        (username, password_hash, 'admin', _now(), _now())
    )
    conn.commit()


def _now():
    import datetime
    return datetime.datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')


# ---------- Management helpers (list, edit, delete) ----------
def list_users(conn: sqlite3.Connection):
    cur = conn.execute("SELECT id, username, role, created_at FROM users ORDER BY username")
    rows = cur.fetchall()
    if not rows:
        print('No users found.')
        return
    print('\nUsers:')
    print('ID\tUsername\tRole\tCreated')
    for r in rows:
        print(f"{r[0]}\t{r[1]}\t{r[2]}\t{r[3]}")


def get_user(conn: sqlite3.Connection, username: str):
    cur = conn.execute("SELECT id, username, role FROM users WHERE username=? LIMIT 1", (username,))
    return cur.fetchone()


def count_admins(conn: sqlite3.Connection) -> int:
    cur = conn.execute("SELECT COUNT(*) FROM users WHERE role='admin'")
    return int(cur.fetchone()[0])


def update_user_password(conn: sqlite3.Connection, username: str, cost: int):
    pwd1 = getpass.getpass('New password: ')
    pwd2 = getpass.getpass('Confirm password: ')
    if pwd1 != pwd2:
        print('Passwords do not match.')
        return
    if len(pwd1) < 6:
        print('Password must be at least 6 characters.')
        return
    hashed = php_bcrypt_hash(pwd1, cost)
    conn.execute("UPDATE users SET password=?, updated_at=? WHERE username=?", (hashed, _now(), username))
    conn.commit()
    print('Password updated.')


def update_user_role(conn: sqlite3.Connection, username: str):
    role = input("New role ('admin' or 'user'): ").strip().lower()
    if role not in ('admin', 'user'):
        print('Invalid role.')
        return
    # Guard: don't remove last admin
    if role == 'user':
        admins = count_admins(conn)
        cur = conn.execute("SELECT role FROM users WHERE username=?", (username,))
        row = cur.fetchone()
        if row and row[0] == 'admin' and admins <= 1:
            print('Cannot demote the last admin.')
            return
    conn.execute("UPDATE users SET role=?, updated_at=? WHERE username=?", (role, _now(), username))
    conn.commit()
    print('Role updated.')


def rename_user(conn: sqlite3.Connection, old_username: str):
    new_username = input('New username: ').strip()
    if not new_username:
        print('Username cannot be empty.')
        return
    if user_exists(conn, new_username):
        print('Username already exists.')
        return
    conn.execute("UPDATE users SET username=?, updated_at=? WHERE username=?", (new_username, _now(), old_username))
    conn.commit()
    print(f'Username changed to {new_username}.')


def delete_user(conn: sqlite3.Connection, username: str, root_username: str):
    if username == root_username:
        print('Refusing to delete the authenticated root admin.')
        return
    cur = conn.execute("SELECT role FROM users WHERE username=?", (username,))
    row = cur.fetchone()
    if not row:
        print('User not found.')
        return
    role = row[0]
    if role == 'admin' and count_admins(conn) <= 1:
        print('Cannot delete the last admin.')
        return
    confirm = input(f"Type DELETE to confirm removal of '{username}': ").strip()
    if confirm != 'DELETE':
        print('Aborted.')
        return
    conn.execute("DELETE FROM users WHERE username=?", (username,))
    conn.commit()
    print('User deleted.')


def main():
    parser = argparse.ArgumentParser(description='Admin management console')
    parser.add_argument('--reset-password', metavar='USERNAME', help='Force reset the password of USERNAME (bypass root auth; interactive confirmation required)')
    parser.add_argument('--bootstrap', action='store_true', help='Create initial admin if none exists')
    args = parser.parse_args()

    conf = read_env()
    cost = int((conf.get('BCRYPT_ROUNDS') or '12').strip())
    db_file = resolve_sqlite_path(conf)

    if not os.path.isfile(db_file):
        print(f"ERROR: SQLite database not found at: {db_file}")
        print('Tip: run your Laravel migrations to create database/database.sqlite')
        sys.exit(1)

    conn = sqlite3.connect(db_file)
    try:
        # Bootstrap mode: create the very first admin
        if args.bootstrap:
            if count_admins(conn) > 0:
                print('Bootstrap aborted: admins already exist.')
                return
            print('Bootstrap: create initial admin (no existing admins).')
            username = input('Initial admin username: ').strip()
            if not username:
                print('Username cannot be empty.')
                return
            if user_exists(conn, username):
                print('Username already exists.')
                return
            pwd1 = getpass.getpass('Password: ')
            pwd2 = getpass.getpass('Confirm password: ')
            if pwd1 != pwd2:
                print('Passwords do not match.')
                return
            if len(pwd1) < 6:
                print('Password must be at least 6 characters.')
                return
            hashed = php_bcrypt_hash(pwd1, cost)
            create_admin(conn, username, hashed)
            print(f'Success: initial admin "{username}" created.')
            return

        # Forced reset password mode
        if args.reset_password:
            uname = args.reset_password.strip()
            row = get_user(conn, uname)
            if not row:
                print('User not found.')
                return
            print('WARNING: You are about to FORCE reset the password (no root auth).')
            print('Type the exact username to confirm.')
            check = input('Confirm username: ').strip()
            if check != uname:
                print('Mismatch. Aborted.')
                return
            update_user_password(conn, uname, cost)
            return

        # Normal interactive mode (requires root admin auth)
        if count_admins(conn) == 0:
            print('ERROR: No admin accounts exist. Use --bootstrap first.')
            sys.exit(1)

        print('Authenticate as an existing admin to continue:')
        root_user = input('Root admin username: ').strip()
        root_pwd = getpass.getpass('Root admin password: ')
        if not root_user or not root_pwd:
            print('ERROR: Username and password are required.')
            sys.exit(1)
        row = conn.execute("SELECT password FROM users WHERE username=? AND role='admin' LIMIT 1", (root_user,)).fetchone()
        if not row:
            print('ERROR: Admin not found or not an admin.')
            sys.exit(1)
        try:
            if not php_password_verify(root_pwd, row[0]):
                print('ERROR: Invalid root admin credentials.')
                sys.exit(1)
        except RuntimeError as e:
            print(f'ERROR: {e}')
            sys.exit(1)

        # Menu loop
        while True:
            print('\nAdmin Management')
            print('1) List users')
            print('2) Create admin')
            print('3) Edit user')
            print('4) Delete user')
            print('0) Quit')
            choice = input('Choose: ').strip()
            if choice == '1':
                list_users(conn)
            elif choice == '2':
                username = input('New admin username: ').strip()
                if not username:
                    print('Username cannot be empty.')
                    continue
                if user_exists(conn, username):
                    print('ERROR: Username already exists in users table.')
                    continue
                pwd1 = getpass.getpass('Password: ')
                pwd2 = getpass.getpass('Confirm password: ')
                if pwd1 != pwd2:
                    print('Passwords do not match.')
                    continue
                if len(pwd1) < 6:
                    print('Password must be at least 6 characters.')
                    continue
                hashed = php_bcrypt_hash(pwd1, cost)
                create_admin(conn, username, hashed)
                print(f'Success: admin user "{username}" created.')
            elif choice == '3':
                uname = input('Username to edit: ').strip()
                if not get_user(conn, uname):
                    print('User not found.')
                    continue
                print(' a) Change password')
                print(' b) Change role')
                print(' c) Rename username')
                sub = input('Select: ').strip().lower()
                if sub == 'a':
                    update_user_password(conn, uname, cost)
                elif sub == 'b':
                    update_user_role(conn, uname)
                elif sub == 'c':
                    rename_user(conn, uname)
                else:
                    print('Invalid selection.')
            elif choice == '4':
                uname = input('Username to delete: ').strip()
                delete_user(conn, uname, root_user)
            elif choice == '0':
                print('Bye.')
                break
            else:
                print('Invalid choice.')
    finally:
        conn.close()


if __name__ == '__main__':
    main()
