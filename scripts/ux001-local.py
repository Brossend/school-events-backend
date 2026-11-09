"""Create an isolated, loopback-only UX001 environment without touching existing data."""
import argparse
import base64
import os
from pathlib import Path
import re
import secrets
import subprocess

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--start', action='store_true', help='Create private env if missing, build and start the separate Compose project')
parser.add_argument('--env-file', type=Path, default=Path.home() / '.codex' / 'ux001' / 'practice.env')
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
env_file = args.env_file.resolve()
if env_file.is_relative_to(root):
    parser.error('Keep credentials outside the repository')
if not args.start:
    print(f'Dry-run: project school-ux001, API 127.0.0.1:8020; private env: {env_file}. No changes.')
    raise SystemExit(0)
if not env_file.exists():
    env_file.parent.mkdir(parents=True, exist_ok=True)
    values = {
        'APP_NAME': 'SchoolEvents', 'APP_ENV': 'local', 'APP_DEBUG': 'false',
        'APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
        'APP_URL': 'http://localhost:8020', 'FRONTEND_URL': 'http://localhost:3030',
        'DB_CONNECTION': 'pgsql', 'DB_HOST': 'postgres', 'DB_PORT': '5432',
        'DB_DATABASE': 'school_events_ux001', 'DB_USERNAME': 'school_ux001_app',
        'DB_PASSWORD': secrets.token_hex(32), 'POSTGRES_USER': 'school_ux001_owner',
        'POSTGRES_PASSWORD': secrets.token_hex(32), 'POSTGRES_DB': 'postgres',
        'REDIS_HOST': 'redis', 'REDIS_CLIENT': 'predis', 'REDIS_PREFIX': 'ux001_clean_',
        'SESSION_DRIVER': 'redis', 'SESSION_COOKIE': 'schoolux001session',
        'SESSION_SECURE_COOKIE': 'false', 'SESSION_SAME_SITE': 'lax', 'SESSION_DOMAIN': 'null',
        'CACHE_STORE': 'redis', 'QUEUE_CONNECTION': 'redis', 'LLM_PROVIDER': 'mock',
        'MAIL_MAILER': 'log', 'SANCTUM_STATEFUL_DOMAINS': 'localhost:3030,localhost:8020',
        'CORS_ALLOWED_ORIGINS': 'http://localhost:3030', 'CORS_SUPPORTS_CREDENTIALS': 'true',
    }
    descriptor = os.open(env_file, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, 'w', encoding='utf-8') as file:
        file.write(''.join(f'{key}={value}\n' for key, value in values.items()))
values = {}
for line in env_file.read_text(encoding='utf-8').splitlines():
    if not line.strip() or line.lstrip().startswith('#'):
        continue
    key, separator, value = line.partition('=')
    if not separator or key in values or not re.fullmatch(r'[A-Z][A-Z0-9_]*', key):
        parser.error('Invalid or duplicate environment key; no containers were changed')
    values[key] = value
required = {
    'APP_ENV': 'local', 'DB_CONNECTION': 'pgsql', 'DB_HOST': 'postgres',
    'DB_PORT': '5432', 'DB_DATABASE': 'school_events_ux001',
    'DB_USERNAME': 'school_ux001_app', 'POSTGRES_USER': 'school_ux001_owner',
    'POSTGRES_DB': 'postgres', 'REDIS_HOST': 'redis',
}
for key, expected in required.items():
    if values.get(key) != expected:
        parser.error(f'{key} must be {expected}; no containers were changed')
for key in ('DB_PASSWORD', 'POSTGRES_PASSWORD'):
    if not re.fullmatch(r'[0-9a-f]{64}', values.get(key, '')):
        parser.error(f'{key} must be a generated 64-character hex secret')
try:
    if not values.get('APP_KEY', '').startswith('base64:') or len(base64.b64decode(values['APP_KEY'][7:], validate=True)) != 32:
        raise ValueError
except (ValueError, KeyError):
    parser.error('APP_KEY must contain a base64-encoded 32-byte key')
if os.name == 'nt':
    principal = subprocess.check_output(['whoami'], text=True).strip()
    subprocess.run(['icacls', str(env_file), '/inheritance:r', '/grant:r', principal + ':F'], check=True, stdout=subprocess.DEVNULL)
else:
    env_file.chmod(0o600)
environment = {key: value for key, value in os.environ.items() if not key.startswith('COMPOSE_')}
environment['UX001_ENV_FILE'] = str(env_file)
command = ['docker', 'compose', '--project-name', 'school-ux001', '--env-file', str(env_file), '-f', 'docker/ux001/compose.yml']
subprocess.run(command + ['up', '-d', '--build'], cwd=root, env=environment, check=True)
subprocess.run(command + ['exec', '-T', 'app', 'php', 'artisan', 'migrate', '--no-interaction'], cwd=root, env=environment, check=True)
print('Ready. Run practice:calendar dry-run, then --apply; bootstrap the first school with the interactive password prompt. No existing data was deleted.')
