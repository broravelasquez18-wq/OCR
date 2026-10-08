import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parents[1] / 'ocr'))
from procesar import extraer

class NuipMrzTest(unittest.TestCase):
    def test_nuip_segunda_linea_antes_que_serial(self):
        for pais in ['COL', 'C0L']:
            texto = '044330624\nICCOL044330624844010<<<<<<<<<<\n0603283F3404104' + pais + '1234567890<9'
            self.assertEqual(extraer(texto), ('1234567890','mrz'))

    def test_no_adivinar_digitos_del_nuip(self):
        self.assertNotEqual(extraer('0603283F3404104C0L12345678O0<9'), ('1234567800','mrz'))

    def test_linea_serial_no_es_nuip(self):
        self.assertEqual(extraer('ICCOL044330624844010<<<<<<<<<<'), (None,'desconocida'))

    def test_numero_frontal_no_cambia(self):
        self.assertEqual(extraer('NUMERO 1.234.567.890'), ('1234567890','frontal'))

if __name__ == '__main__': unittest.main()
