// Vercel Serverless Function
// このファイルはサーバー側で実行されるため、ブラウザからAPIキーは見えません。

const MODEL = "gemini-3.8-flash";

function readingText(result) {
  const parts = result?.candidates?.[0]?.content?.parts || [];
  return parts
    .filter((part) => part && part.text && !part.thought)
    .map((part) => part.text)
    .join("\n")
    .trim();
}

export default async function handler(req, res) {
  if (req.method !== "POST") {
    return res.status(405).json({ error: "この操作はできません。" });
  }

  const apiKey = process.env.GEMINI_API_KEY;
  if (!apiKey) {
    return res.status(500).json({ error: "サーバーにAPIキーが設定されていません。" });
  }

  const image = req.body && req.body.image;
  const mimeType = req.body && req.body.mimeType === "image/png" ? "image/png" : "image/jpeg";
  if (typeof image !== "string" || image.length < 100) {
    return res.status(400).json({ error: "顔の画像を取得できませんでした。カメラを向けて、もう一度お試しください。" });
  }
  if (image.length > 3500000) {
    return res.status(413).json({ error: "画像が大きすぎます。もう一度お試しください。" });
  }

  const prompt = `あなたは熟練の人相学鑑定師です。この写真の顔から、日本語で鑑定してください。
次の3つの見出しを、それぞれ独立した行として書いてください。
顔から読み取れる性格
いまの運気
これからへのアドバイス
見出しの前後に # や * は付けないでください。各見出しのあとに、丁寧な語りかける口調で2〜3段落書いてください。医療診断や本人の特定はしないでください。`;

  const payload = {
    contents: [{
      role: "user",
      parts: [
        { text: prompt },
        { inlineData: { mimeType, data: image } }
      ]
    }],
    generationConfig: {
      thinkingConfig: { thinkingLevel: "low" },
      maxOutputTokens: 2048
    }
  };

  try {
    const response = await fetch(
      `https://generativelanguage.googleapis.com/v1beta/models/${MODEL}:generateContent?key=${apiKey}`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      }
    );

    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
      const detail = result?.error?.message || "";
      const friendly = /model/i.test(detail)
        ? "鑑定用のAIモデルに接続できませんでした。しばらくしてから、もう一度お試しください。"
        : "鑑定サービスに接続できませんでした。しばらくしてから、もう一度お試しください。";
      return res.status(502).json({ error: friendly });
    }

    if (result?.promptFeedback?.blockReason) {
      return res.status(422).json({
        error: "この画像では鑑定できませんでした。顔がはっきり写るようにして、もう一度お試しください。"
      });
    }

    const text = readingText(result);
    if (!text) {
      return res.status(502).json({ error: "鑑定結果が得られませんでした。もう一度お試しください。" });
    }

    return res.status(200).json({ text });
  } catch (err) {
    return res.status(500).json({ error: "鑑定中に問題が起きました。少し待ってから、もう一度お試しください。" });
  }
}