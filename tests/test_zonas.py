import sys
from pathlib import Path
import unittest
import numpy as np

sys.path.insert(0, str(Path(__file__).parents[1] / 'ocr'))
from zonas import ReconocedorZonas
from procesar import _rapidocr_a_data, zonas_de_lectura, leer_campos, texto_desde_datos


class ZonasTest(unittest.TestCase):
    def ejecutar(self, textos, largas=()):
        llamadas = []
        def original(imagenes, word_boxes):
            indices = [int(im[0, 0, 0]) for im in imagenes]
            llamadas.extend(indices)
            return [(textos[i], .99) for i in indices], .1
        lector = ReconocedorZonas(original, _rapidocr_a_data, zonas_de_lectura,
                                  leer_campos, texto_desde_datos)
        lector.shape = (800, 600, 3)
        lector.cajas = [np.array([[10, i*40], [200, i*40], [200, i*40+20], [10, i*40+20]])
                        for i in range(len(textos))]
        if textos[-1] == 'PIE DE PAGINA EXTENSO':
            lector.cajas[-1][:, 1] += 450
        imagenes = [np.full((20, 100 if i < len(textos)-1 else 300, 3), i, np.uint8)
                    for i in range(len(textos))]
        for i in largas:
            imagenes[i] = np.full((20, 500, 3), i, np.uint8)
        resultado, _ = lector(imagenes)
        return llamadas, resultado, lector.metricas

    def test_omite_linea_ajena_si_campos_completos(self):
        llamadas, resultado, metricas = self.ejecutar([
            'NUMERO 1.234.567.890', 'PEREZ LOPEZ', 'APELLIDOS',
            'ANA MARIA', 'NOMBRES', 'FECHA DE NACIMIENTO 10-ENE-2000',
            'PIE DE PAGINA EXTENSO'])
        self.assertNotIn(6, llamadas)
        self.assertEqual(resultado[-1], ('', 0.0))
        self.assertFalse(metricas['lectura_completa'])

    def test_formato_desconocido_completa_sin_repetir(self):
        llamadas, resultado, metricas = self.ejecutar(['TEXTO', 'FORMATO DESCONOCIDO'])
        self.assertEqual(sorted(llamadas), [0, 1])
        self.assertEqual(len(llamadas), len(set(llamadas)))
        self.assertEqual(resultado[1][0], 'FORMATO DESCONOCIDO')
        self.assertTrue(metricas['lectura_completa'])

    def test_mrz_completa_la_evidencia(self):
        llamadas, _, metricas = self.ejecutar([
            'NUMERO 1.234.567.890', 'PEREZ LOPEZ', 'APELLIDOS',
            'ANA MARIA', 'NOMBRES', 'FECHA DE NACIMIENTO 10-ENE-2000',
            'ICCOL1234567890<', 'PIE DE PAGINA EXTENSO'])
        self.assertEqual(len(llamadas), 8)
        self.assertTrue(metricas['lectura_completa'])

    def test_serial_largo_fuera_de_zonas_se_conserva(self):
        llamadas, resultado, _ = self.ejecutar([
            'NUMERO 1.234.567.890', 'PEREZ LOPEZ', 'APELLIDOS',
            'ANA MARIA', 'NOMBRES', 'FECHA DE NACIMIENTO 10-ENE-2000',
            'PIE DE PAGINA EXTENSO'], largas=(6,))
        self.assertIn(6, llamadas)
        self.assertEqual(resultado[6][0], 'PIE DE PAGINA EXTENSO')
        self.assertEqual(len(llamadas), len(set(llamadas)))

    def test_dato_faltante_recuperado_en_linea_larga(self):
        llamadas, resultado, metricas = self.ejecutar([
            'NUMERO 1.234.567.890', 'PEREZ LOPEZ', 'APELLIDOS',
            'ANA MARIA', 'NOMBRES', 'FECHA DE NACIMIENTO 10-ENE-2000'])
        self.assertEqual(sorted(llamadas), list(range(6)))
        self.assertEqual(len(llamadas), len(set(llamadas)))
        self.assertIn('NACIMIENTO', resultado[-1][0])


if __name__ == '__main__':
    unittest.main()
