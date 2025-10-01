#!/usr/bin/env python3
"""
Simple smoke test for the trained model.
Usage:
  python check_model_smoke.py --model model.joblib

This script loads the model and runs a small set of labeled samples. It exits 0 on success, non-zero on failure.
"""
import argparse
import sys
import joblib

SAMPLES = [
    ("営業のご案内です。格安で御社のリードを獲得できます。詳しくはご連絡ください。", 1),
    ("商品の在庫について教えてください。注文番号123の件です。", 0),
]


def main():
    p = argparse.ArgumentParser()
    p.add_argument('--model', '-m', required=True, help='Path to model.joblib')
    args = p.parse_args()

    try:
        clf = joblib.load(args.model)
    except Exception as e:
        print('ERROR: failed to load model:', e, file=sys.stderr)
        sys.exit(2)

    texts = [s for s,_ in SAMPLES]
    expected = [e for _,e in SAMPLES]
    try:
        preds = clf.predict(texts)
    except Exception as e:
        print('ERROR: model.predict failed:', e, file=sys.stderr)
        sys.exit(3)

    # check exact match for smoke samples
    ok = True
    for i,(exp,pred) in enumerate(zip(expected,preds)):
        if int(pred) != int(exp):
            print('MISMATCH: sample', i, 'expected', exp, 'got', pred, file=sys.stderr)
            ok = False
        else:
            print('OK: sample', i, 'expected', exp)

    if not ok:
        print('Smoke test FAILED', file=sys.stderr)
        sys.exit(4)

    print('Smoke test PASSED')
    sys.exit(0)


if __name__ == '__main__':
    main()
