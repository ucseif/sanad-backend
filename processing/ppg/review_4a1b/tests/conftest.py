from pathlib import Path
import sys

REVIEW = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(REVIEW))
sys.path.insert(0, str(REVIEW.parent/'src'))
