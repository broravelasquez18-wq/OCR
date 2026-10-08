import os
import sys
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

import cv2
import numpy as np

sys.path.insert(0, str(Path(__file__).parents[1] / 'ocr'))
import procesar as p

FRENTE = ('REPUBLICA DE COLOMBIA\nIDENTIFICACION PERSONAL\nCEDULA DE CIUDADANIA\n'
          '1.234.567.890\nNUMERO\nPEREZ LOPEZ\nAPELLIDOS\nANA MARIA\nNOMBRES')
REVERSO = 'FECHA DE NACIMIENTO\n10-ENE-2000\nP-123-456-F-1234567898-20060103'


class ConflictoSerialTest(unittest.TestCase):
    def leer(self, frente, reverso, relectura=None):
        img = np.full((300, 450, 3), 255, dtype=np.uint8)
        data = {'text': ['texto'], 'conf': [90]}
        textos = [frente, reverso] + ([relectura] if relectura is not None else [])
        with patch.object(cv2, 'imread', return_value=img), \
                patch.object(p.os.path, 'isfile', return_value=True), \
                patch.object(p, 'recortar_documentos', return_value=[img, img]), \
                patch.object(p, 'unir_caras', return_value=(img, [(0, 150), (150, 300)])), \
                patch.object(p, 'datos_de_cara', return_value=data), \
                patch.object(p, 'construir_ocr', return_value=('rapidocr', Mock(return_value=data), None)), \
                patch.object(p, 'texto_desde_datos', side_effect=textos), \
                patch.object(p, 'salida') as salida, \
                patch.object(sys, 'argv', ['procesar.py', 'fixture']), \
                patch.dict(os.environ, {'OCR_BARCODE': '0'}):
            p.main()
            return salida.call_args

    def test_conserva_frente_sin_mezclar_fecha_del_reverso(self):
        args, kwargs = self.leer(FRENTE, REVERSO, REVERSO)
        self.assertEqual(args[0], '1234567890')
        self.assertEqual(kwargs['nombres'], 'ANA MARIA')
        self.assertIsNone(kwargs['fecha'])
        self.assertIn('solo la lectura del frente', kwargs['error'])
        self.assertIn('[REVISION OCR]', args[3])
        self.assertNotIn('2000', args[3].split('[REVISION OCR]')[0])

    def test_relectura_exacta_permite_unir(self):
        args, kwargs = self.leer(FRENTE, REVERSO, REVERSO.replace('1234567898', '1234567890'))
        self.assertEqual(args[0], '1234567890')
        self.assertEqual(kwargs['fecha'], '2000-01-10')
        self.assertIsNone(kwargs['error'])

    def test_dos_frentes_distintos_siguen_rechazados(self):
        args, kwargs = self.leer(FRENTE, FRENTE.replace('1.234.567.890', '1.234.567.898'))
        self.assertIsNone(args[0])
        self.assertIn('identificaciones distintas', kwargs['error'])

    def test_mrz_distinta_no_se_descarta(self):
        args, kwargs = self.leer(FRENTE, 'COL1234567898<')
        self.assertIsNone(args[0])
        self.assertIn('identificaciones distintas', kwargs['error'])

    def test_sin_encabezado_no_se_elige_frente(self):
        args, kwargs = self.leer(FRENTE.replace('CEDULA DE CIUDADANIA', ''), REVERSO)
        self.assertIsNone(args[0])

    def test_frente_sin_nombres_no_pierde_numero_ante_serial(self):
        args, kwargs = self.leer(FRENTE.split('PEREZ')[0], REVERSO, REVERSO)
        self.assertEqual(args[0], '1234567890')
        self.assertIsNone(kwargs['nombres'])
        self.assertIsNone(kwargs['fecha'])


if __name__ == '__main__':
    unittest.main()
