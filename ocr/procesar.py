#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Lector OCR del numero de una cedula colombiana.

El numero aparece en hasta tres zonas con distinta fiabilidad; se intenta la
mas confiable disponible y se cae a la siguiente:

  1) MRZ  (cedulas nuevas, formato ICAO):  ...COL<numero>...
  2) Codigo de barras / PDF417 impreso (cedulas amarillas viejas): -M-0000numero-
  3) Numero frontal impreso: 1.234.567.890

Uso:
    python3 procesar.py <ruta_imagen> [--lang spa]

Salida (stdout): SIEMPRE un JSON, incluso ante error:
    {"numero": "1127070363", "zona": "mrz", "confianza": 92.4, "texto_crudo": "..."}
    {"numero": null, "zona": "desconocida", "confianza": 0, "texto_crudo": "", "error": "..."}
"""

import sys
import time
import json
import re
import datetime
import unicodedata
from difflib import SequenceMatcher
from functools import lru_cache
from contextlib import redirect_stdout
from io import StringIO

# Idioma de Tesseract (se puede sobreescribir por argumento o env).
import os
LANG = os.environ.get("TESSERACT_LANG", "spa")
OCR_METRICS = []


def salida(numero, zona, confianza, texto_crudo, fecha=None, fecha_zona=None, error=None, nombres=None, apellidos=None):
    payload = {
        "numero": numero,
        "nombres": nombres,
        "apellidos": apellidos,
        "zona": zona,
        "confianza": round(float(confianza), 2) if confianza is not None else None,
        "fecha_nacimiento": fecha,        # 'YYYY-MM-DD' o None
        "fecha_zona": fecha_zona,         # 'mrz' | 'frontal' | None
        "texto_crudo": texto_crudo or "",
    }
    if error:
        payload["error"] = error
    print(json.dumps(payload, ensure_ascii=True))


def extraer_nombres(texto):
    lineas = [re.sub(r"\s+", " ", linea).strip().upper() for linea in texto.splitlines() if linea.strip()]
    def encabezado(valor):
        return re.sub(r'[^A-Z]', '', valor) in {
            'COLOMBIA', 'COLOMBI', 'LOMBIA', 'REPUBLICA', 'REPUBLI',
            'REPUBLICADECOLOMBIA', 'PURLTCADE',
        }
    for linea in lineas:
        if re.fullmatch(r"[A-Z<]{10,44}", linea) and "<<" in linea:
            apellido_mrz, nombre_mrz = linea.split("<<", 1)
            apellidos = " ".join(apellido_mrz.split("<")).strip()
            nombres = " ".join(nombre_mrz.split("<")).strip()
            if apellidos and nombres and not re.search(r"\b(COL|REPUBLICA)\b", apellidos):
                return nombres, apellidos

    def candidato(valor):
        valor = valor.strip(" :.-")
        valor = re.sub(r'(?<=[A-Z])!(?=\s|$)', 'I', valor)
        if encabezado(valor):
            return None
        normal = unicodedata.normalize("NFD", valor)
        normal = "".join(letra for letra in normal if not unicodedata.combining(letra))
        if not re.fullmatch(r"[A-ZÁÉÍÓÚÜÑ]+(?:[ '\-][A-ZÁÉÍÓÚÜÑ]+)*", valor):
            return None
        if len(valor) < 2 or len(valor) > 150:
            return None
        if re.search(r"\b(REPUBLICA|COLOMBIA|IDENTIFICACION|PERSONAL|CEDULA|CIUDADANIA|NACIMIENTO|EXPEDICION|SEXO|ESTATURA|FIRMA|FECHA|NOMBRES?|APELLIDOS?)\b", normal):
            return None
        return valor

    etiquetas = []
    for indice, linea in enumerate(lineas):
        etiqueta = re.match(r"^(NOMBRES?|APELLIDOS?)\b\s*:?[ ]*(.*)$", linea)
        if etiqueta:
            etiquetas.append((indice, 'nombres' if etiqueta.group(1).startswith('NOMBRE') else 'apellidos', etiqueta.group(2)))
        else:
            compacta = re.sub(r'[^A-Z]', '', linea)
            for campo in ('nombres', 'apellidos'):
                if len(compacta) >= 6 and len(linea) <= 14 and SequenceMatcher(None, compacta, campo.upper()).ratio() >= 0.85:
                    etiquetas.append((indice, campo, ''))
                    break
    if not etiquetas:
        return None, None
    primer_indice = etiquetas[0][0]
    def vecino_valido(indice, paso):
        vecino = indice + paso
        # El OCR puede intercalar un fragmento del encabezado junto al apellido.
        # Solo saltar ruido identificado, nunca otras etiquetas o campos.
        while 0 <= vecino < len(lineas) and abs(vecino - indice) <= 3:
            if encabezado(lineas[vecino]):
                vecino += paso
                continue
            return candidato(lineas[vecino])
        return None
    valores_antes = vecino_valido(primer_indice, -1) is not None
    digital = bool(re.search(r'\bNUIP(?=\s|\d)', texto, re.I))
    if digital:
        valores_antes = False
    if not digital and re.search(r'\bNUMER[O0]\b', texto, re.I):
        valores_antes = True
    valores = {}
    for indice, campo, inline in etiquetas:
        valor = candidato(inline)
        if valor is None and not inline:
            valor = vecino_valido(indice, -1 if valores_antes else 1)
        if valor:
            valores[campo] = valor
    if digital and valores.get('nombres') and not valores.get('apellidos'):
        # Etiqueta Apellidos perdida: exigir el valor entre NUIP y Nombres.
        # No usar firma, encabezado ni una posición absoluta de la imagen.
        for indice, campo, _ in etiquetas:
            if campo == 'nombres' and indice >= 2 and re.search(r'\bNUIP\s*[\d.]', lineas[indice - 2]):
                apellido = candidato(lineas[indice - 1])
                if apellido:
                    valores['apellidos'] = apellido
    return valores.get('nombres'), valores.get('apellidos')


def texto_desde_datos(data):
    lineas = {}
    for indice, palabra in enumerate(data.get('text', [])):
        if not palabra.strip():
            continue
        clave = tuple(data[campo][indice] for campo in ('page_num', 'block_num', 'par_num', 'line_num'))
        lineas.setdefault(clave, []).append(palabra)
    return '\n'.join(' '.join(palabras) for palabras in lineas.values())


def normaliza(n):
    """Deja solo digitos y quita ceros de relleno del codigo de barras."""
    n = re.sub(r"\D", "", n or "")
    return n.lstrip("0") or n


def recortar_documentos(img, cv2):
    alto, ancho = img.shape[:2]
    escala = min(1.0, 1200 / max(alto, ancho))
    miniatura = cv2.resize(img, None, fx=escala, fy=escala) if escala < 1 else img
    gris = cv2.cvtColor(miniatura, cv2.COLOR_BGR2GRAY)
    mascara = cv2.inRange(gris, 0, 235)
    kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (7, 7))
    cerrada = cv2.morphologyEx(mascara, cv2.MORPH_CLOSE, kernel)
    contornos, _ = cv2.findContours(cerrada, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    area_pagina = gris.shape[0] * gris.shape[1]
    candidatos = []
    for contorno in contornos:
        izquierda, arriba, anchura, altura = cv2.boundingRect(contorno)
        area = anchura * altura
        proporcion = anchura / altura
        if area < area_pagina * 0.025 or not 1.15 <= proporcion <= 2.2:
            continue
        if cv2.contourArea(contorno) / area < 0.72:
            continue
        candidatos.append((izquierda, arriba, anchura, altura))
    if not 1 <= len(candidatos) <= 2:
        return [img]
    restante = mascara.copy()
    for izquierda, arriba, anchura, altura in candidatos:
        restante[arriba:arriba + altura, izquierda:izquierda + anchura] = 0
    if cv2.countNonZero(restante) > max(1, cv2.countNonZero(mascara)) * 0.12:
        return [img]
    recortes = []
    for izquierda, arriba, anchura, altura in sorted(candidatos, key=lambda caja: (caja[1], caja[0])):
        margen = max(8, round(min(anchura, altura) * 0.025 / escala))
        inicio_horizontal = max(0, int(izquierda / escala) - margen)
        inicio_vertical = max(0, int(arriba / escala) - margen)
        fin_horizontal = min(ancho, int((izquierda + anchura) / escala) + margen)
        fin_vertical = min(alto, int((arriba + altura) / escala) + margen)
        recortes.append(img[inicio_vertical:fin_vertical, inicio_horizontal:fin_horizontal])
    return recortes


def preprocesa(img, cv2):
    return cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)


def frente_inequivoco(resultados, textos):
    """Localiza un único frente con número impreso, sin inferirlo del Excel.

    El texto del serial bajo el PDF417 también pasa por OCR y puede contener
    errores. Si contradice al frente se permite conservar SOLO ese frente;
    nunca se usa este criterio para unir dos identidades o aceptar una fecha
    del reverso en conflicto.
    """
    candidatos = []
    for indice, (resultado, texto) in enumerate(zip(resultados, textos)):
        compacto = re.sub(r'[^A-Z]', '', texto.upper())
        if resultado['zona'] != 'frontal' or not resultado['numero']:
            continue
        if 'CEDULADECIUDADANIA' not in compacto or 'IDENTIFICACIONPERSONAL' not in compacto:
            continue
        impresos = {normaliza(m.group(0)) for m in re.finditer(
            r'(?<![\d.,])\d{1,3}(?:[.,]\d{3}){2,3}(?![\d.,])', texto)}
        if impresos == {resultado['numero']}:
            candidatos.append(indice)
    if len(candidatos) != 1:
        return None
    frente = candidatos[0]
    # Dos frentes, una MRZ o una lectura binaria contradictoria no se resuelven
    # eligiendo una identidad. Este rescate se limita al serial impreso por OCR.
    if any(i != frente and r['numero'] and r['zona'] != 'codigo_barras'
           for i, r in enumerate(resultados)):
        return None
    return frente


def extraer(texto):
    """Devuelve (numero, zona) segun la zona mas confiable encontrada."""
    # En estos reversos TD1 el NUIP está tras la nacionalidad de la segunda
    # línea; la primera contiene el serial de la tarjeta. El OCR confunde O/0
    # en COL: normalizar solo esa etiqueta, nunca los dígitos del documento.
    for linea in texto.upper().splitlines():
        compacta = re.sub(r'\s+', '', linea)
        m = re.fullmatch(r'\d{7}[MFX<]\d{7}C[O0]L(\d{6,10})<+[\d<]*', compacta)
        if m:
            return normaliza(m.group(1)), 'mrz'
    # No extraer el serial de una primera línea TD1 como identificación.
    texto = '\n'.join(linea for linea in texto.splitlines()
                      if not re.match(r'^I[<C].*C[O0]L', re.sub(r'\s+', '', linea.upper())))
    m = re.search(r"COL(\d{6,10})<", texto)              # 1) MRZ (cedulas nuevas)
    if m:
        return normaliza(m.group(1)), "mrz"
    m = re.search(r"\bP[-\s]+\d+[-\s]+\d+[-\s]+[MF][-\s]+0*(\d{6,10})(?=[-\s]|$)", texto)
    if m:
        return normaliza(m.group(1)), "codigo_barras"
    m = re.search(r"-[MF]-0*(\d{6,10})-", texto)          # 2) codigo de barras (amarillas)
    if m:
        return normaliza(m.group(1)), "codigo_barras"
    for coincidencia in re.finditer(r"(?<![\d.,])\d{1,3}(?:[.,]\d{3}){2,3}(?![\d.,])", texto):
        candidato = normaliza(coincidencia.group(0))
        if 6 <= len(candidato) <= 10:
            return candidato, "frontal"
    # 4) ultimo recurso: una secuencia larga de digitos suelta (8-10)
    m = re.search(r"\b(\d{8,10})\b", texto)
    if m:
        return normaliza(m.group(1)), "frontal"
    return None, "desconocida"


# Meses en espanol (abreviados) tal como aparecen impresos en la cedula.
MESES = {
    "ENE": 1, "FEB": 2, "MAR": 3, "ABR": 4, "MAY": 5, "JUN": 6,
    "JUL": 7, "AGO": 8, "SEP": 9, "SET": 9, "OCT": 10, "NOV": 11, "DIC": 12,
}


def _anio_completo(yy):
    """Convierte 'YY' de dos digitos a anio de 4: <= anio actual => 20YY, si no 19YY."""
    import datetime
    yy = int(yy)
    actual2 = datetime.date.today().year % 100
    return (2000 if yy <= actual2 else 1900) + yy


def extraer_fecha(texto):
    """
    Devuelve (fecha 'YYYY-MM-DD', zona) de la fecha de nacimiento, o (None, None).

    1) MRZ (fiable): linea 2 = YYMMDD C sexo(M/F/X/<) YYMMDD...
    2) Texto frontal: 'DD-MMM-AAAA' (meses en espanol), preferido cerca de "NACIM".
    """
    import datetime
    up = (texto or "").upper()

    # 1) MRZ: la fecha de nacimiento va al inicio de la 2a linea.
    m = re.search(r"(\d{2})(\d{2})(\d{2})\d[MFX<]\d{6}", up)
    if m:
        try:
            f = datetime.date(_anio_completo(m.group(1)), int(m.group(2)), int(m.group(3)))
            if f <= datetime.date.today():
                return f.isoformat(), "mrz"
        except ValueError:
            pass

    anio_actual = datetime.date.today().year
    lineas = up.splitlines()
    tramos = []
    for indice, linea in enumerate(lineas):
        if 'NACIM' not in linea:
            continue
        tramos.append(linea)
        for vecino in (indice + 1, indice - 1):
            if 0 <= vecino < len(lineas) and not re.search(r'EXPEDI|VENCIM', lineas[vecino]):
                tramos.append(lineas[vecino])
        # En cédulas digitales el OCR intercala la etiqueta G.S. de la otra
        # columna entre nacimiento y su valor. Saltar solo esa etiqueta.
        if indice + 2 < len(lineas) and re.sub(r'[^A-Z]', '', lineas[indice + 1]) in ('GS', 'GSRH'):
            if not re.search(r'EXPEDI|VENCIM', lineas[indice + 2]):
                tramos.append(lineas[indice + 2])
    for tramo in tramos:
        for mm in re.finditer(r"(\d{1,2})[\s\-/.]*([A-Z]{3})[\s\-/.]*(\d{4})", tramo):
            mes = MESES.get(mm.group(2))
            if not mes:
                continue
            dd, yyyy = int(mm.group(1)), int(mm.group(3))
            if not (1900 <= yyyy <= anio_actual):   # descartar anios claramente erroneos
                continue
            try:
                fecha = datetime.date(yyyy, mes, dd)
                if fecha <= datetime.date.today():
                    return fecha.isoformat(), "frontal"
            except ValueError:
                continue

        for encontrada in re.finditer(r'\b(\d{1,2})[./-](\d{1,2})[./-](\d{4})\b', tramo):
            try:
                fecha = datetime.date(int(encontrada.group(3)), int(encontrada.group(2)), int(encontrada.group(1)))
                if 1900 <= fecha.year <= anio_actual and fecha <= datetime.date.today():
                    return fecha.isoformat(), 'frontal'
            except ValueError:
                continue
    return None, None


def confianza_media(data):
    """Confianza media (0-100) de los tokens no vacios de image_to_data."""
    confs = []
    for c, t in zip(data.get("conf", []), data.get("text", [])):
        try:
            c = float(c)
        except (TypeError, ValueError):
            continue
        if c >= 0 and (t or "").strip():
            confs.append(c)
    return sum(confs) / len(confs) if confs else 0.0


def zonas_de_lectura(data, ancho, alto):
    anclas = {}
    for indice, palabra in enumerate(data.get('text', [])):
        normal = ''.join(letra for letra in unicodedata.normalize('NFD', palabra.upper()) if not unicodedata.combining(letra))
        normal = re.sub(r'[^A-Z]', '', normal)
        if float(data['conf'][indice]) < 30:
            continue
        campo = None
        if normal in ('NUMERO', 'NUIP'):
            campo = 'numero'
        elif normal in ('NOMBRES', 'NOMBRE', 'APELLIDOS', 'APELLIDO'):
            campo = 'nombres'
        elif normal.startswith('NACIM'):
            campo = 'fecha'
        if campo:
            anclas.setdefault(campo, []).append((data['left'][indice], data['top'][indice], data['width'][indice], data['height'][indice]))
    zonas = []
    for campo, cajas in anclas.items():
        izquierda = min(caja[0] for caja in cajas)
        arriba = min(caja[1] for caja in cajas)
        abajo = max(caja[1] + caja[3] for caja in cajas)
        altura_texto = max(caja[3] for caja in cajas)
        if campo == 'nombres':
            inicio_horizontal = max(0, izquierda - int(ancho * 0.08))
            fin_horizontal = ancho if izquierda > ancho * 0.4 else int(ancho * 0.68)
            inicio_vertical = max(0, arriba - altura_texto * 4)
            fin_vertical = min(alto, abajo + altura_texto * 4)
        elif campo == 'numero':
            inicio_horizontal = max(0, izquierda - altura_texto)
            fin_horizontal = ancho
            inicio_vertical = max(0, arriba - altura_texto)
            fin_vertical = min(alto, abajo + altura_texto * 3)
        else:
            inicio_horizontal = 0
            fin_horizontal = ancho
            inicio_vertical = max(0, arriba - altura_texto * 2)
            fin_vertical = min(alto, arriba + altura_texto * 5)
        if fin_horizontal > inicio_horizontal and fin_vertical > inicio_vertical:
            zonas.append((campo, (inicio_horizontal, inicio_vertical, fin_horizontal, fin_vertical)))
    return zonas


def leer_campos(texto):
    numero, zona = extraer(texto)
    fecha, fecha_zona = extraer_fecha(texto)
    nombres, apellidos = extraer_nombres(texto)
    return {'numero': numero, 'zona': zona, 'fecha': fecha, 'fecha_zona': fecha_zona,
            'nombres': nombres, 'apellidos': apellidos}


def leer_por_zonas(recorte, data, cv2, ocr, solo_faltantes=False):
    texto = texto_desde_datos(data)
    resultado = leer_campos(texto)
    avisos = []
    evidencias = [texto]
    for campo, caja in zonas_de_lectura(data, recorte.shape[1], recorte.shape[0]):
        # Con motores fuertes (RapidOCR) la lectura general ya es fiable; la
        # re-lectura por zona solo se usa para recuperar campos faltantes, lo
        # que evita pasadas caras y redundantes del modelo.
        campos_zona = ['nombres', 'apellidos'] if campo == 'nombres' else [campo]
        if solo_faltantes and all(resultado.get(c) for c in campos_zona):
            continue
        izquierda, arriba, derecha, abajo = caja
        region = recorte[arriba:abajo, izquierda:derecha]
        gris = cv2.cvtColor(region, cv2.COLOR_BGR2GRAY)
        escala = min(2.0, max(1.0, 1200 / gris.shape[1]))
        if escala > 1:
            gris = cv2.resize(gris, None, fx=escala, fy=escala, interpolation=cv2.INTER_CUBIC)
        gris = cv2.copyMakeBorder(gris, 15, 15, 15, 15, cv2.BORDER_CONSTANT, value=255)
        lectura = ocr(gris)
        texto_zona = texto_desde_datos(lectura)
        evidencias.append('[ZONA ' + campo.upper() + ']\n' + texto_zona)
        candidato = leer_campos(texto_zona)
        for atributo in campos_zona:
            nuevo = candidato[atributo]
            anterior = resultado[atributo]
            if nuevo and anterior and nuevo != anterior:
                resultado[atributo] = None
                avisos.append('Lecturas distintas en ' + atributo + '; verifica la imagen.')
            elif nuevo and not anterior:
                resultado[atributo] = nuevo
                if atributo == 'numero':
                    resultado['zona'] = candidato['zona']
                elif atributo == 'fecha':
                    resultado['fecha_zona'] = candidato['fecha_zona']
    return resultado, '\n\n'.join(evidencias), avisos


def _rapidocr_a_data(resultado):
    """Convierte la salida de RapidOCR ([[caja, texto, score], ...]) al formato
    DICT que usa el resto del pipeline (compatible con pytesseract Output.DICT).

    Cada linea detectada se divide en palabras con cajas aproximadas para que la
    deteccion de anclas por posicion (zonas_de_lectura) siga funcionando.
    """
    claves = ('text', 'conf', 'left', 'top', 'width', 'height',
              'page_num', 'block_num', 'par_num', 'line_num')
    data = {clave: [] for clave in claves}
    if not resultado:
        return data
    for numero_linea, item in enumerate(resultado):
        try:
            caja, texto, score = item[0], item[1], item[2]
        except (TypeError, IndexError, ValueError):
            continue
        xs = [punto[0] for punto in caja]
        ys = [punto[1] for punto in caja]
        x0, y0, x1, y1 = int(min(xs)), int(min(ys)), int(max(xs)), int(max(ys))
        ancho_linea = max(1, x1 - x0)
        alto_linea = max(1, y1 - y0)
        palabras = str(texto).split()
        if not palabras:
            continue
        total = max(1, sum(len(p) for p in palabras))
        conf = float(score) * 100.0
        cursor = x0
        for palabra in palabras:
            ancho_palabra = max(1, int(round(ancho_linea * len(palabra) / total)))
            data['text'].append(palabra)
            data['conf'].append(conf)
            data['left'].append(int(cursor))
            data['top'].append(y0)
            data['width'].append(ancho_palabra)
            data['height'].append(alto_linea)
            data['page_num'].append(0)
            data['block_num'].append(0)
            data['par_num'].append(0)
            data['line_num'].append(numero_linea)
            cursor += ancho_palabra + max(1, int(ancho_linea * 0.02))
    return data


@lru_cache(maxsize=2)
def construir_ocr(nombre, lang, cv2):
    """Devuelve (nombre_real, funcion_ocr, aviso).

    funcion_ocr(imagen) -> dict con el mismo formato de pytesseract Output.DICT.
    Motores soportados: 'rapidocr' (rapidocr-onnxruntime) y 'tesseract'.
    Si se pide RapidOCR y no esta disponible, cae a Tesseract con un aviso.
    """
    nombre = (nombre or '').strip().lower()
    aviso = None

    if nombre in ('', 'auto', 'rapidocr'):
        try:
            from rapidocr_onnxruntime import RapidOCR
            # Limitar los hilos de onnxruntime evita que varios procesos OCR en
            # paralelo (una imagen por nucleo) compitan por la CPU. Configurable.
            kwargs = {}
            try:
                hilos = int(os.environ.get('OCR_THREADS', '') or 0)
            except ValueError:
                hilos = 0
            if hilos > 0:
                kwargs['intra_op_num_threads'] = hilos
                kwargs['inter_op_num_threads'] = 1
            # En CPU, lotes de seis líneas rellenan todas al ancho de la mayor.
            # Una línea evita ese trabajo extra, especialmente con seriales largos.
            kwargs['rec_batch_num'] = max(1, min(16, int(os.environ.get('OCR_REC_BATCH', '1'))))
            # Reutilizar buffers y evitar que los hilos de tres modelos ocupen
            # CPU mientras están inactivos en los motores persistentes.
            from rapidocr_onnxruntime.utils.infer_engine import OrtInferSession
            options_factory = OrtInferSession._init_sess_opts
            def session_options(config):
                options = options_factory(config)
                options.enable_cpu_mem_arena = True
                options.add_session_config_entry('session.intra_op.allow_spinning', '0')
                options.add_session_config_entry('session.inter_op.allow_spinning', '0')
                return options
            OrtInferSession._init_sess_opts = staticmethod(session_options)
            try:
                motor = RapidOCR(**kwargs)
            finally:
                OrtInferSession._init_sess_opts = staticmethod(options_factory)
            # Límite configurable del lado mayor de la detección.
            try:
                lado = int(os.environ.get('RAPIDOCR_DET_SIDE', '960'))
            except ValueError:
                lado = 960
            # Esta versión ignora det_limit_side_len en __call__ y su modo
            # "min" amplía los recortes. Limitar solo la entrada del detector
            # conserva la imagen original para reconocimiento y sus coordenadas.
            from rapidocr_onnxruntime.ch_ppocr_det.utils import DetPreProcess
            lado = max(320, min(2048, lado))
            detector = motor.text_det
            detector.get_preprocess = lambda max_wh: DetPreProcess(
                lado, 'max', detector.mean, detector.std)
            zonas_motor = None
            if os.environ.get('OCR_ZONES', '1') != '0':
                from zonas import instalar
                zonas_motor = instalar(motor, _rapidocr_a_data, zonas_de_lectura,
                                       leer_campos, texto_desde_datos)

            def _ocr(imagen):
                imagen_color = imagen
                if getattr(imagen, 'ndim', 3) == 2:
                    imagen_color = cv2.cvtColor(imagen, cv2.COLOR_GRAY2BGR)
                started = time.perf_counter()
                resultado, tiempos = motor(imagen_color)
                OCR_METRICS.append({'total_ms': round((time.perf_counter() - started) * 1000),
                    'etapas_ms': [round(float(t) * 1000) for t in (tiempos or [])],
                    'alto': int(imagen_color.shape[0]), 'ancho': int(imagen_color.shape[1]),
                    'zonas': dict(zonas_motor.metricas) if zonas_motor else None})
                return _rapidocr_a_data(resultado)

            return 'rapidocr', _ocr, aviso
        except Exception as e:  # noqa: BLE001
            if nombre == 'rapidocr':
                aviso = 'RapidOCR no disponible (%s); se uso Tesseract.' % e
            # cae a Tesseract

    # Tesseract (motor por defecto y de respaldo).
    import pytesseract
    tbin = os.environ.get('TESSERACT_BIN')
    if tbin:
        pytesseract.pytesseract.tesseract_cmd = tbin

    def _ocr(imagen):
        return pytesseract.image_to_data(
            imagen, lang=lang, config='--psm 6',
            output_type=pytesseract.Output.DICT)

    return 'tesseract', _ocr, aviso


def variantes_codigo_barras(img, cv2):
    """Crear cada variante solo cuando la anterior no permitió leer el código."""
    yield img
    gris = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    yield gris
    yield cv2.resize(gris, None, fx=1.5, fy=1.5, interpolation=cv2.INTER_CUBIC)
    _, otsu = cv2.threshold(gris, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    yield otsu
    del otsu
    yield cv2.rotate(gris, cv2.ROTATE_180)
    yield cv2.rotate(gris, cv2.ROTATE_90_CLOCKWISE)
    yield cv2.rotate(gris, cv2.ROTATE_90_COUNTERCLOCKWISE)


def leer_codigo_barras(img, cv2):
    """Lee el numero de cedula del codigo PDF417 del reverso (cedulas viejas).

    El payload de la Registraduria empieza con el numero de documento a 10
    digitos (con ceros a la izquierda), verificado como constante (offset 0).
    Devuelve el numero normalizado o None. El QR de la cedula digital nueva
    lleva contenido comprimido/firmado y no se intenta decodificar.
    """
    try:
        import zxingcpp
    except Exception:
        return None
    for variante in variantes_codigo_barras(img, cv2):
        try:
            resultados = zxingcpp.read_barcodes(variante, formats=zxingcpp.BarcodeFormat.PDF417)
        except Exception:
            continue
        for lectura in resultados or []:
            if str(lectura.format) != "PDF417":
                continue
            crudo = (lectura.bytes or b"").decode("latin-1", "ignore")
            coincidencia = re.match(r"\s*(\d{8,10})", crudo)
            if coincidencia:
                numero = normaliza(coincidencia.group(1))
                if 6 <= len(numero) <= 10:
                    return numero
    return None


def unir_caras(imagenes, cv2, max_lado=1600):
    """Compone las dos caras sin volver a segmentar la imagen resultante."""
    import numpy as np
    preparadas = []
    for img in imagenes:
        recortes = recortar_documentos(img, cv2)
        # Quitar el fondo de una página únicamente si contiene una sola cara.
        cara = recortes[0] if len(recortes) == 1 else img
        if max_lado > 0 and max(cara.shape[:2]) > max_lado:
            escala = max_lado / max(cara.shape[:2])
            cara = cv2.resize(cara, None, fx=escala, fy=escala, interpolation=cv2.INTER_AREA)
        preparadas.append(preprocesa(cara, cv2))
    margen = 32
    ancho = max(cara.shape[1] for cara in preparadas)
    alto = sum(cara.shape[0] for cara in preparadas) + margen
    imagen = np.full((alto, ancho), 255, dtype=np.uint8)
    limites = []
    arriba = 0
    for cara in preparadas:
        h, w = cara.shape[:2]
        imagen[arriba:arriba + h, :w] = cara
        limites.append((arriba, arriba + h))
        arriba += h + margen
    return imagen, limites


def datos_de_cara(data, inicio, fin):
    # Clasificar por centro de la caja; conservar agrupación de líneas y confianza.
    indices = [i for i in range(len(data.get('text', [])))
               if inicio <= float(data['top'][i]) + float(data['height'][i]) / 2 < fin]
    return {clave: [valores[i] for i in indices] for clave, valores in data.items()}


def leer_caras_juntas(rutas, lang, engine):
    import cv2
    cv2.setNumThreads(max(1, int(os.environ.get('OCR_THREADS', '2') or 2)))
    imagenes = [cv2.imread(ruta) for ruta in rutas]
    if any(img is None for img in imagenes):
        raise ValueError('No se pudo leer una de las caras del documento.')
    try:
        max_lado = int(os.environ.get('OCR_MAX_SIDE', '1600') or 0)
    except ValueError:
        max_lado = 1600
    imagen, limites = unir_caras(imagenes, cv2, max_lado)
    motor, ocr, aviso = construir_ocr(engine, lang, cv2)
    # Una sola inferencia: sin recortar de nuevo ni repetir OCR por zonas.
    data = ocr(imagen)
    caras = []
    barcode = os.environ.get('OCR_BARCODE', '1').strip().lower() not in ('0', 'false', 'no', 'off')
    for original, (inicio, fin) in zip(imagenes, limites):
        datos = datos_de_cara(data, inicio, fin)
        texto = texto_desde_datos(datos)
        campos = leer_campos(texto)
        confianza = confianza_media(datos)
        if barcode:
            try:
                numero_barras = leer_codigo_barras(original, cv2)
            except Exception:
                numero_barras = None
            if numero_barras and numero_barras == campos['numero']:
                campos['numero'], campos['zona'] = numero_barras, 'pdf417'
                confianza = 99.0
                texto += '\n[NUMERO VERIFICADO EN PDF417]'
        caras.append({
            'numero': campos['numero'], 'zona': campos['zona'],
            'nombres': campos['nombres'], 'apellidos': campos['apellidos'],
            'fecha_nacimiento': campos['fecha'], 'fecha_zona': campos['fecha_zona'],
            'confianza': round(confianza if campos['numero'] else min(confianza, 20.0), 2),
            'texto_crudo': '[MOTOR ' + motor + ' / LECTURA CONJUNTA]\n' + texto,
            'error': aviso,
        })
    return {'caras': caras}


def main():
    if len(sys.argv) < 2:
        salida(None, "desconocida", 0, "", error="falta la ruta de la imagen")
        return

    ruta = sys.argv[1]

    # Permitir --lang
    lang = LANG
    if "--lang" in sys.argv:
        try:
            lang = sys.argv[sys.argv.index("--lang") + 1]
        except IndexError:
            pass

    # Motor OCR: env OCR_ENGINE (rapidocr|tesseract) o --engine.
    engine = os.environ.get("OCR_ENGINE", "tesseract")
    if "--engine" in sys.argv:
        try:
            engine = sys.argv[sys.argv.index("--engine") + 1]
        except IndexError:
            pass

    if not os.path.isfile(ruta):
        salida(None, "desconocida", 0, "", error="la imagen no existe: %s" % ruta)
        return

    # Import tardio de OpenCV para poder emitir JSON de error si falta.
    try:
        import cv2
        cv2.setNumThreads(max(1, int(os.environ.get('OCR_THREADS', '2') or 2)))
    except Exception as e:  # noqa: BLE001
        salida(None, "desconocida", 0, "", error="dependencias OCR no disponibles: %s" % e)
        return

    img = cv2.imread(ruta)
    if img is None:
        salida(None, "desconocida", 0, "", error="no se pudo leer la imagen (formato no soportado?)")
        return

    # El prefijo PDF417 puede ser un serial. La lectura del texto continúa
    # siempre para recuperar nombres/fecha; no se acepta el prefijo como ID.
    if os.environ.get('OCR_BARCODE', '1').strip().lower() not in ('0', 'false', 'no', 'off'):
        try:
            numero_barras = leer_codigo_barras(img, cv2)
        except Exception:  # noqa: BLE001
            numero_barras = None
        # El prefijo de algunos PDF417 es un serial, no la identificación.
        # No omitir nombres/fecha ni reemplazar la lectura por ese prefijo.

    # Reducir el tamano antes del OCR acelera la inferencia sin perder el texto
    # de una cedula. Se hace DESPUES del codigo de barras (que si necesita
    # resolucion). Configurable por OCR_MAX_SIDE (0 = no reescalar).
    try:
        max_lado = int(os.environ.get('OCR_MAX_SIDE', '1600') or 0)
    except ValueError:
        max_lado = 1600
    if max_lado and max(img.shape[:2]) > max_lado:
        escala = max_lado / max(img.shape[:2])
        img = cv2.resize(img, None, fx=escala, fy=escala, interpolation=cv2.INTER_AREA)

    # Construir el motor OCR (RapidOCR o Tesseract). Carga el modelo una sola vez.
    try:
        nombre_motor, ocr, aviso_motor = construir_ocr(engine, lang, cv2)
    except Exception as e:  # noqa: BLE001
        salida(None, "desconocida", 0, "", error="no se pudo iniciar el motor OCR (%s): %s" % (engine, e))
        return

    try:
        lecturas = []
        resultados = []
        textos = []
        avisos = []
        if aviso_motor:
            avisos.append(aviso_motor)
        recortes = recortar_documentos(img, cv2)
        datos_conjuntos = None
        if nombre_motor == 'rapidocr' and len(recortes) == 2 and '--binarizado' not in sys.argv:
            # También las páginas que YA contienen ambas caras deben leerse
            # una sola vez; antes se hacían dos inferencias y relecturas por zona.
            conjunta, limites = unir_caras(recortes, cv2, max_lado=0)
            lectura_conjunta = ocr(conjunta)
            datos_conjuntos = [datos_de_cara(lectura_conjunta, a, b) for a, b in limites]
        for indice, recorte in enumerate(recortes):
            procesada = preprocesa(recorte, cv2)
            if '--binarizado' in sys.argv:
                procesada = cv2.bilateralFilter(procesada, 9, 75, 75)
                _, procesada = cv2.threshold(procesada, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
            data = datos_conjuntos[indice] if datos_conjuntos is not None else ocr(procesada)
            lecturas.append(data)
            if datos_conjuntos is not None:
                evidencia = texto_desde_datos(data)
                resultado, advertencias = leer_campos(evidencia), []
            else:
                resultado, evidencia, advertencias = leer_por_zonas(
                    recorte, data, cv2, ocr, solo_faltantes=(nombre_motor == 'rapidocr'))
            resultados.append(resultado)
            textos.append(evidencia)
            avisos.extend(advertencias)
        texto = ('[MOTOR ' + nombre_motor + ']\n\n') + '\n\n'.join(textos)

        # Reconciliacion del numero entre recortes. Muchos escaneos traen frente y
        # reverso en una sola imagen: recortar_documentos los separa en 2 recortes
        # y el reverso a veces lee un numero espurio (fecha/serial). Solo se confia
        # en numeros "fuertes": de zonas estructuradas (mrz/pdf417/codigo) o los que
        # van acompanados de nombre en el MISMO recorte (el frente). Un numero suelto
        # sin nombre no compite. Se rechaza solo si hay 2 numeros fuertes distintos
        # (dos personas reales).
        def _numero_fuerte(r):
            if not r['numero']:
                return False
            if r['zona'] in ('mrz', 'pdf417', 'codigo_barras'):
                return True
            return bool(r['nombres'] or r['apellidos'])

        frente = frente_inequivoco(resultados, textos)
        numeros_fuertes = {r['numero'] for i, r in enumerate(resultados)
                           if _numero_fuerte(r) or i == frente}
        if len(numeros_fuertes) > 1 and frente is not None:
            # Releer el reverso por separado, a su resolución completa. No
            # corregir dígitos por similitud ni sustituirlos por los del Excel.
            for i, resultado in enumerate(resultados):
                if i == frente or resultado['zona'] != 'codigo_barras':
                    continue
                data = ocr(preprocesa(recortes[i], cv2))
                evidencia = texto_desde_datos(data)
                candidato = leer_campos(evidencia)
                textos[i] += '\n[RELECTURA SERIAL]\n' + evidencia
                if candidato['zona'] == 'codigo_barras' and candidato['numero'] == resultados[frente]['numero']:
                    resultados[i] = candidato
            texto = ('[MOTOR ' + nombre_motor + ']\n\n') + '\n\n'.join(textos)
            numeros_fuertes = {r['numero'] for i, r in enumerate(resultados)
                               if _numero_fuerte(r) or i == frente}
        if len(numeros_fuertes) > 1:
            if frente is not None:
                r = resultados[frente]
                aviso = ('El número del frente difiere del serial leído en el reverso. '
                         'Se conservó solo la lectura del frente; revisa el reverso y completa los datos faltantes.')
                evidencia_segura = ('[MOTOR ' + nombre_motor + ']\n' + textos[frente]
                                    + '\n[REVISION OCR]\n' + aviso + '\n[REVERSO NO ASOCIADO]\n'
                                    + '\n\n'.join(t for i, t in enumerate(textos) if i != frente))
                salida(r['numero'], r['zona'], confianza_media(lecturas[frente]),
                       evidencia_segura,
                       nombres=r['nombres'], apellidos=r['apellidos'],
                       fecha=r['fecha'], fecha_zona=r['fecha_zona'], error=aviso)
                return
            salida(None, 'desconocida', 0, texto, error='Los recortes tienen identificaciones distintas. Revisa la imagen; no se mezclaron los datos.')
            return

        numeros_todos = {r['numero'] for r in resultados if r['numero']}
        if numeros_fuertes:
            numero = next(iter(numeros_fuertes))
        elif len(numeros_todos) == 1:
            numero = next(iter(numeros_todos))
        else:
            numero = None  # varios numeros debiles y contradictorios: dejar pendiente

        combinado = {'numero': numero}
        zona = next((r['zona'] for r in resultados if numero and r['numero'] == numero), 'desconocida')
        for campo in ('nombres', 'apellidos', 'fecha'):
            valores = {resultado[campo] for resultado in resultados if resultado[campo]}
            combinado[campo] = next(iter(valores)) if len(valores) == 1 else None
            if len(valores) > 1:
                avisos.append('Los recortes difieren en ' + campo + '; verifica la imagen.')

        # Confianza: si no hallamos numero, baja; si si, usamos la media de tesseract.
        conf = sum(confianza_media(data) for data in lecturas) / len(lecturas)

        if numero is None:
            conf = min(conf, 20.0)

        fecha = combinado['fecha']
        fecha_zona = next((resultado['fecha_zona'] for resultado in resultados if fecha and resultado['fecha'] == fecha), None)
        salida(numero, zona, conf, texto, fecha=fecha, fecha_zona=fecha_zona,
               nombres=combinado['nombres'], apellidos=combinado['apellidos'], error=' '.join(avisos) or None)
    except Exception as e:  # noqa: BLE001
        salida(None, "desconocida", 0, "", error="fallo el OCR: %s" % e)


def cli():
    OCR_METRICS.clear()
    if '--reverso' in sys.argv and '--caras-separadas' not in sys.argv:
        try:
            rutas = [sys.argv[1], sys.argv[sys.argv.index('--reverso') + 1]]
            lang = sys.argv[sys.argv.index('--lang') + 1] if '--lang' in sys.argv else LANG
            engine = sys.argv[sys.argv.index('--engine') + 1] if '--engine' in sys.argv else os.environ.get('OCR_ENGINE', 'tesseract')
            print(json.dumps(leer_caras_juntas(rutas, lang, engine), ensure_ascii=False))
        except Exception as e:
            print(json.dumps({'caras': [], 'error': 'Falló la lectura conjunta: ' + str(e)}, ensure_ascii=False))
    elif '--reverso' in sys.argv:
        # Reutilizar el modelo cargado al leer la segunda cara del documento.
        indice = sys.argv.index('--reverso')
        rutas = [sys.argv[1], sys.argv[indice + 1]]
        caras = []
        for ruta in rutas:
            sys.argv[1] = ruta
            captura = StringIO()
            with redirect_stdout(captura):
                main()
            caras.append(json.loads(captura.getvalue().strip().splitlines()[-1]))
        print(json.dumps({'caras': caras}, ensure_ascii=False))
    else:
        main()


if __name__ == '__main__':
    cli()
