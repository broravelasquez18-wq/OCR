"""Motor local persistente. Un proceso por slot, con token y plazo por lectura."""
import argparse
import contextlib
import hmac
import io
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import sys
import threading
import time

ROOT = Path(__file__).resolve().parents[1]


def serve(state):
    startup_watchdog = threading.Timer(25, lambda: os._exit(124))
    startup_watchdog.daemon = True
    startup_watchdog.start()
    import cv2
    import procesar
    cv2.setNumThreads(max(1, int(os.environ.get('OCR_THREADS', '2'))))
    engine = os.environ.get('OCR_ENGINE', 'rapidocr')
    lang = os.environ.get('TESSERACT_LANG', 'spa')
    # Cargar una vez; construir_ocr mantiene el motor en su caché del proceso.
    procesar.construir_ocr(engine, lang, cv2)
    startup_watchdog.cancel()
    token = secrets.token_hex(32)
    with socket.socket() as server:
        server.bind(('127.0.0.1', 0))
        server.listen(2)
        server.settimeout(300)  # Liberar memoria tras cinco minutos sin trabajo.
        temporary = state.with_suffix('.new')
        temporary.write_text(json.dumps({'port': server.getsockname()[1], 'token': token}), encoding='utf-8')
        os.replace(temporary, state)
        while True:
            try:
                connection, _ = server.accept()
            except socket.timeout:
                return
            with connection:
                connection.settimeout(2)
                try:
                    raw = connection.makefile('rb').readline(65537)
                    if len(raw) > 65536:
                        continue
                    request = json.loads(raw)
                    if not hmac.compare_digest(str(request.get('token', '')), token):
                        continue
                    if request.get('action') == 'ping':
                        response = {'ok': True}
                    else:
                        arguments = request['args']
                        validate(arguments)
                        remaining = min(25.0, float(request['deadline']) - time.time())
                        if remaining <= 0:
                            raise TimeoutError('Se agotó el tiempo de OCR.')
                        # Terminar también una inferencia nativa bloqueada. El próximo
                        # pedido recreará este slot; no queda un OCR huérfano.
                        watchdog = threading.Timer(remaining, lambda: os._exit(124))
                        watchdog.daemon = True
                        watchdog.start()
                        try:
                            captured = io.StringIO()
                            sys.argv = ['procesar.py', *arguments]
                            with contextlib.redirect_stdout(captured):
                                procesar.cli()
                            response = {'ok': True, 'output': captured.getvalue(), 'inferencia': procesar.OCR_METRICS}
                        finally:
                            watchdog.cancel()
                    connection.sendall((json.dumps(response, ensure_ascii=False) + '\n').encode('utf-8'))
                except Exception as error:
                    try:
                        connection.sendall((json.dumps({'ok': False, 'error': str(error)}) + '\n').encode('utf-8'))
                    except OSError:
                        pass


def validate(arguments):
    if not isinstance(arguments, list) or not arguments or not all(isinstance(x, str) for x in arguments):
        raise ValueError('Argumentos OCR inválidos.')
    paths = [arguments[0]]
    index = 1
    while index < len(arguments):
        flag = arguments[index]
        if flag == '--binarizado':
            index += 1
        elif flag in ('--lang', '--reverso') and index + 1 < len(arguments):
            if flag == '--reverso':
                paths.append(arguments[index + 1])
            index += 2
        else:
            raise ValueError('Opción OCR inválida.')
    for name in paths:
        path = Path(name).resolve()
        if not path.is_relative_to(ROOT / 'storage' / 'uploads') or not path.is_file():
            raise ValueError('La imagen no pertenece al almacenamiento OCR.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--state', required=True)
    parser.add_argument('--start', action='store_true')
    args = parser.parse_args()
    state = Path(args.state).resolve()
    if not state.is_relative_to(ROOT / 'storage' / 'tmp' / 'ocr-workers'):
        raise ValueError('Directorio de estado inválido.')
    if args.start:
        flags = subprocess.CREATE_NO_WINDOW | subprocess.DETACHED_PROCESS if os.name == 'nt' else 0
        with open(state.with_suffix('.log'), 'ab') as log:
            subprocess.Popen([sys.executable, '-X', 'utf8', __file__, '--state', str(state)],
                             stdin=subprocess.DEVNULL, stdout=log, stderr=log,
                             cwd=ROOT, creationflags=flags, start_new_session=os.name != 'nt')
    else:
        serve(state)
