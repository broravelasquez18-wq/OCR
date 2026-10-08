import sys
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch
import unittest
import cv2
import numpy as np

sys.path.insert(0, str(Path(__file__).parents[1] / 'ocr'))
import procesar


class BarcodeEfficiencyTest(unittest.TestCase):
    def test_exito_inicial_no_crea_copias(self):
        imagen = np.zeros((100, 200, 3), dtype=np.uint8)
        vision = Mock()
        zxing = SimpleNamespace(BarcodeFormat=SimpleNamespace(PDF417='pdf417'),
            read_barcodes=Mock(return_value=[SimpleNamespace(format='PDF417', bytes=b'0012345678')]))
        with patch.dict(sys.modules, {'zxingcpp': zxing}):
            self.assertEqual(procesar.leer_codigo_barras(imagen, vision), '12345678')
        vision.cvtColor.assert_not_called()
        vision.resize.assert_not_called()
        vision.rotate.assert_not_called()
        zxing.read_barcodes.assert_called_once()

    def test_mismas_variantes_pixeles_y_orden(self):
        imagen = np.random.default_rng(42).integers(0, 256, (30, 50, 3), dtype=np.uint8)
        gris = cv2.cvtColor(imagen, cv2.COLOR_BGR2GRAY)
        _, otsu = cv2.threshold(gris, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
        antes = [imagen, gris, cv2.resize(gris, None, fx=1.5, fy=1.5, interpolation=cv2.INTER_CUBIC),
                 otsu, cv2.rotate(gris, cv2.ROTATE_180), cv2.rotate(gris, cv2.ROTATE_90_CLOCKWISE),
                 cv2.rotate(gris, cv2.ROTATE_90_COUNTERCLOCKWISE)]
        despues = list(procesar.variantes_codigo_barras(imagen, cv2))
        self.assertEqual(len(antes), len(despues))
        for a, b in zip(antes, despues):
            np.testing.assert_array_equal(a, b)

    def test_agota_variantes_sin_codigo(self):
        zxing = SimpleNamespace(BarcodeFormat=SimpleNamespace(PDF417='pdf417'),
            read_barcodes=Mock(return_value=[]))
        with patch.dict(sys.modules, {'zxingcpp': zxing}):
            self.assertIsNone(procesar.leer_codigo_barras(np.zeros((40, 80, 3), np.uint8), cv2))
        self.assertEqual(zxing.read_barcodes.call_count, 7)


if __name__ == '__main__':
    unittest.main()
