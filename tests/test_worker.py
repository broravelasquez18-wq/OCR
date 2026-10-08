import importlib.util
from pathlib import Path
import unittest

root = Path(__file__).parents[1]
spec = importlib.util.spec_from_file_location('worker', root / 'ocr/worker.py')
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)

class WorkerValidationTest(unittest.TestCase):
    def test_rechaza_archivo_fuera_de_uploads(self):
        with self.assertRaises(ValueError):
            worker.validate([str(root / '.env')])

    def test_rechaza_opciones_no_permitidas(self):
        with self.assertRaises(ValueError):
            worker.validate(['imagen.jpg', '--engine', 'otro'])
        with self.assertRaises(ValueError):
            worker.validate(['imagen.jpg', '--reverso'])

    def test_rechaza_argumentos_no_textuales(self):
        with self.assertRaises(ValueError):
            worker.validate([{'path': 'imagen.jpg'}])

if __name__ == '__main__':
    unittest.main()
