import importlib.util
import os
from pathlib import Path
import unittest
import sys
from unittest.mock import patch

import cv2
import numpy as np
sys.path.insert(0, str(Path(__file__).parents[1] / 'ocr'))

spec = importlib.util.spec_from_file_location('procesar', Path(__file__).parents[1] / 'ocr/procesar.py')
ocr = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ocr)


class LecturaConjuntaTest(unittest.TestCase):
    def test_detector_limita_resolucion_sin_ampliar_recortes(self):
        from rapidocr_onnxruntime import RapidOCR
        instancias = []
        def crear(**kwargs):
            motor = RapidOCR(**kwargs)
            instancias.append(motor)
            return motor
        ocr.construir_ocr.cache_clear()
        with patch('rapidocr_onnxruntime.RapidOCR', side_effect=crear), \
                patch.dict(os.environ, {'OCR_THREADS': '2', 'RAPIDOCR_DET_SIDE': '960', 'OCR_REC_BATCH': '1'}):
            nombre, _, aviso = ocr.construir_ocr('rapidocr', 'spa', cv2)
        self.assertEqual(nombre, 'rapidocr')
        self.assertIsNone(aviso)
        detector = instancias[0].text_det
        recognizer = instancias[0].text_rec
        self.assertEqual(recognizer.rec_batch_num, 1)
        options = recognizer.session.session.get_session_options()
        self.assertTrue(options.enable_cpu_mem_arena)
        self.assertEqual(options.get_session_config_entry('session.intra_op.allow_spinning'), '0')
        self.assertEqual(options.get_session_config_entry('session.inter_op.allow_spinning'), '0')
        pequena = detector.get_preprocess(320)(np.zeros((160, 320, 3), dtype=np.uint8))
        grande = detector.get_preprocess(2400)(np.zeros((1200, 2400, 3), dtype=np.uint8))
        self.assertEqual(pequena.shape[2:], (160, 320))
        self.assertEqual(grande.shape[2:], (480, 960))
        ocr.construir_ocr.cache_clear()

    def test_una_inferencia_y_datos_separados_por_cara(self):
        frente = np.full((200, 300, 3), 255, dtype=np.uint8)
        reverso = np.full((100, 250, 3), 255, dtype=np.uint8)
        datos = ocr._rapidocr_a_data([
            ([[5, 20], [180, 20], [180, 35], [5, 35]], '1.234.567.890', 0.98),
            ([[5, 250], [180, 250], [180, 265], [5, 265]], '9.876.543.210', 0.97),
        ])
        with patch.object(cv2, 'imread', side_effect=[frente, reverso]), \
                patch.object(ocr, 'construir_ocr') as construir, \
                patch.dict(os.environ, {'OCR_BARCODE': '0'}):
            from unittest.mock import Mock
            inferir = Mock(return_value=datos)
            construir.return_value = ('rapidocr', inferir, None)
            resultado = ocr.leer_caras_juntas(['frente', 'reverso'], 'spa', 'rapidocr')
        inferir.assert_called_once()
        self.assertEqual(inferir.call_args.args[0].shape, (332, 300))
        self.assertEqual([c['numero'] for c in resultado['caras']], ['1234567890', '9876543210'])

    def test_margen_blanco_y_limite_de_tamano(self):
        img = np.zeros((200, 300, 3), dtype=np.uint8)
        conjunta, limites = ocr.unir_caras([img, img], cv2, max_lado=150)
        self.assertEqual(conjunta.shape, (232, 150))
        self.assertEqual(limites, [(0, 100), (132, 232)])
        self.assertTrue(np.all(conjunta[100:132] == 255))

    def test_imagen_invalida_no_ejecuta_ocr(self):
        with patch.object(cv2, 'imread', return_value=None), patch.object(ocr, 'construir_ocr') as construir:
            with self.assertRaises(ValueError):
                ocr.leer_caras_juntas(['a', 'b'], 'spa', 'rapidocr')
            construir.assert_not_called()

    def test_serial_pdf417_no_reemplaza_identificacion(self):
        from unittest.mock import Mock
        img = np.full((100, 200, 3), 255, dtype=np.uint8)
        data = ocr._rapidocr_a_data([
            ([[5,10],[180,10],[180,25],[5,25]], '1.234.567.890', .98),
            ([[5,150],[180,150],[180,165],[5,165]], '1.234.567.890', .98)])
        with patch.object(cv2,'imread',return_value=img), \
                patch.object(ocr,'construir_ocr',return_value=('rapidocr',Mock(return_value=data),None)), \
                patch.object(ocr,'leer_codigo_barras',return_value='330988239'), \
                patch.dict(os.environ,{'OCR_BARCODE':'1'}):
            caras=ocr.leer_caras_juntas(['a','b'],'spa','rapidocr')['caras']
        self.assertEqual([c['numero'] for c in caras],['1234567890','1234567890'])
        self.assertTrue(all('VERIFICADO EN PDF417' not in c['texto_crudo'] for c in caras))


if __name__ == '__main__':
    unittest.main()
