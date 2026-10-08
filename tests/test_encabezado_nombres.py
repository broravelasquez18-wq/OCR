import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parents[1] / 'ocr'))
from procesar import extraer_nombres

class EncabezadosTest(unittest.TestCase):
    def test_no_tomar_firma_por_direccion_incorrecta(self):
        self.assertEqual(extraer_nombres('17711201\nNUMERO\nOYOLASAB!\nAPELLIDOS\nLEONEL\nNOMBRES\nBeohglpuoot\nFIRMA'), ('LEONEL','OYOLASABI'))
    def test_fragmento_intercalado(self):
        self.assertEqual(extraer_nombres('CEDULA DECIUDADANIA\nQUINTERO MARINEZ\nLOMBIA\nAPELLIDOS\nANTONIO JAVIER\nNOMBRES'), ('ANTONIO JAVIER', 'QUINTERO MARINEZ'))

    def test_no_recuperar_encabezado_sin_apellido(self):
        self.assertIsNone(extraer_nombres('12345\nLOMBIA\nAPELLIDOS\nNOMBRES')[1])

    def test_nombre_legitimo_no_es_fragmento(self):
        self.assertEqual(extraer_nombres('APELLIDOS: LOMBANA\nNOMBRES: ANA'), ('ANA', 'LOMBANA'))

    def test_formato_valores_antes(self):
        self.assertEqual(extraer_nombres('PEREZ\nAPELLIDOS\nANA MARIA\nNOMBRES'), ('ANA MARIA', 'PEREZ'))

    def test_digital_etiqueta_apellidos_perdida(self):
        self.assertEqual(extraer_nombres('NUIP1.234.567.890\nPEREZ LOPEZ\nNombres\nANA MARIA'), ('ANA MARIA','PEREZ LOPEZ'))

    def test_digital_no_confunde_apellido_con_nombre(self):
        self.assertEqual(extraer_nombres('NUIP 1.234.567.890\nApellidos\nPEREZLOPEZ\nNombres\nANA MARIA'), ('ANA MARIA','PEREZLOPEZ'))

    def test_sin_nuip_no_infiere_apellido_perdido(self):
        self.assertIsNone(extraer_nombres('ENCABEZADO\nPEREZ LOPEZ\nNombres\nANA MARIA')[1])

if __name__ == '__main__': unittest.main()
