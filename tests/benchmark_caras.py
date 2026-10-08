"""Compara dos modos sin imprimir datos personales: python tests/benchmark_caras.py frente reverso."""
import json
import os
from pathlib import Path
import subprocess
import sys
import time

root = Path(__file__).parents[1]
env = dict(os.environ, OCR_ENGINE='rapidocr', OCR_THREADS='2', OCR_MAX_SIDE='1600')
comando = [sys.executable, str(root / 'ocr/procesar.py'), sys.argv[1], '--reverso', sys.argv[2]]
resultados = {}
tiempos = {}
for modo, extra in [('separadas', ['--caras-separadas']), ('conjunta', [])]:
    inicio = time.perf_counter()
    proceso = subprocess.run(comando + extra, capture_output=True, encoding='utf-8', env=env, timeout=25, check=True)
    tiempos[modo] = round(time.perf_counter() - inicio, 3)
    resultados[modo] = json.loads(proceso.stdout.strip().splitlines()[-1])['caras']
campos = ['numero', 'nombres', 'apellidos', 'fecha_nacimiento']
print(json.dumps({
    'segundos': tiempos,
    'caras': len(resultados['conjunta']),
    'coincidencias_por_campo': {campo: sum(a.get(campo) == b.get(campo) for a, b in zip(resultados['separadas'], resultados['conjunta'])) for campo in campos},
    'campos_leidos': {modo: sum(bool(cara.get(c)) for cara in caras for c in campos) for modo, caras in resultados.items()},
}, ensure_ascii=False))
