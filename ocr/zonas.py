"""Reconocimiento progresivo por etiquetas, conservando las cajas originales.

El detector localiza todas las líneas una vez. El reconocedor empieza por líneas
cortas (etiquetas), continúa con sus zonas y completa la imagen si faltan datos.
"""
import re
import time


class ReconocedorZonas:
    def __init__(self, original, convertir, zonas, campos, texto):
        self.original = original
        self.convertir, self.zonas = convertir, zonas
        self.campos, self.texto = campos, texto
        self.cajas = []
        self.shape = None
        self.metricas = {}

    def __getattr__(self, nombre):
        return getattr(self.original, nombre)

    def __call__(self, imagenes, return_word_box=False):
        if return_word_box or self.shape is None or len(self.cajas) != len(imagenes):
            return self.original(imagenes, return_word_box)
        inicio = time.perf_counter()
        resultados = {}
        total = len(imagenes)

        def leer(indices):
            pendientes = [i for i in indices if i not in resultados]
            if pendientes:
                valores, _ = self.original([imagenes[i] for i in pendientes], False)
                resultados.update(zip(pendientes, valores))

        def datos():
            return self.convertir([[self.cajas[i], *resultados[i]]
                                   for i in sorted(resultados)
                                   if resultados[i][1] >= 0.5])

        # Las etiquetas suelen ser cortas. No depende de una plantilla fija ni
        # de que el frente esté a la izquierda o arriba del reverso.
        leer(i for i, img in enumerate(imagenes)
             if img.shape[1] / max(1, img.shape[0]) <= 9)
        # MRZ y seriales PDF417 impresos pueden contener otra identificación.
        # Leerlos conserva la reconciliación entre caras aunque estén fuera
        # de las zonas de nombres y fecha; nunca asumir que son decoración.
        leer(i for i, img in enumerate(imagenes)
             if img.shape[1] / max(1, img.shape[0]) >= 20)
        data = datos()
        etiquetas = {re.sub(r'[^A-Z]', '', t.upper()) for t in data['text']}
        conocidas = bool(etiquetas & {'NOMBRE', 'NOMBRES'}) and bool(
            etiquetas & {'APELLIDO', 'APELLIDOS'})
        if conocidas:
            alto, ancho = self.shape[:2]
            regiones = [caja for _, caja in self.zonas(data, ancho, alto)]
            seleccion = []
            for i, caja in enumerate(self.cajas):
                x = sum(p[0] for p in caja) / 4
                y = sum(p[1] for p in caja) / 4
                if any(a <= x <= c and b <= y <= d for a, b, c, d in regiones):
                    seleccion.append(i)
            leer(seleccion)
        campos = self.campos(self.texto(datos()))
        completa = conocidas and all(campos.get(k) for k in
                                     ('numero', 'nombres', 'apellidos', 'fecha'))
        # Las líneas MRZ son también evidencia de identidad. Si aparece una,
        # completar evita omitir otra cara o una identificación contradictoria.
        tiene_mrz = any(re.search(r'(?:I[<C]COL|COL\d{6,10}<|[A-Z]{2}<<[A-Z])', v[0])
                        for v in resultados.values())
        if not completa or tiene_mrz:
            leer(range(total))
        self.metricas = {'lineas_detectadas': total,
                         'lineas_reconocidas': len(resultados),
                         'lectura_completa': len(resultados) == total}
        return [resultados.get(i, ('', 0.0)) for i in range(total)], time.perf_counter() - inicio


def instalar(motor, convertir, zonas, campos, texto):
    reconocedor = ReconocedorZonas(motor.text_rec, convertir, zonas, campos, texto)
    recortar = motor.get_crop_img_list

    def capturar(imagen, cajas):
        reconocedor.cajas = cajas
        reconocedor.shape = imagen.shape
        return recortar(imagen, cajas)

    motor.get_crop_img_list = capturar
    motor.text_rec = reconocedor
    return reconocedor
