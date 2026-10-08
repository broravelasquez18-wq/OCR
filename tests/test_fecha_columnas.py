import sys
import unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).parents[1]/'ocr'))
from procesar import extraer_fecha

class FechaColumnas(unittest.TestCase):
    def test_etiqueta_sangre_intercalada(self):
        self.assertEqual(extraer_fecha('Fecha de nacimiento\nG.S.\n13 ABR 1990\nFecha de expedicion\n20 ABR 2010'),('1990-04-13','frontal'))

    def test_no_salta_cualquier_campo(self):
        self.assertEqual(extraer_fecha('Fecha de nacimiento\nFecha de expedicion\n20 ABR 2010'),(None,None))

    def test_no_salta_a_expedicion(self):
        self.assertEqual(extraer_fecha('Fecha de nacimiento\nG.S.\nFecha de expedicion 20 ABR 2010'),(None,None))

if __name__=='__main__':unittest.main()
