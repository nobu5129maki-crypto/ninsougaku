// Vercel Serverless Function
// このファイルはサーバー側で実行されるため、ブラウザからAPIキーは見えません。

export default async function handler(req, res) {
  // POSTメソッド以外は拒否
  if (req.method !== 'POST') {
    return res.status(405).json({ error: 'Method not allowed' });
  }

  // Vercelの管理画面で設定した環境変数からAPIキーを取得
  const apiKey = process.env.GEMINI_API_KEY;
  
  if (!apiKey) {
    return res.status(500).json({ error: 'API key is not configured on the server' });
  }

  const { image } = req.body;

  const prompt = `あなたは熟練の人相学鑑定師です。この写真から以下の内容を日本語で詳しく鑑定してください。
  1. 顔のパーツから読み取れる基本的な性格
  2. 現在の運気（金運、健康運など）
  3. 未来に向けたポジティブなアドバイス
  丁寧な語りかけるような口調で、各項目を段落に分けて記述してください。`;

  const payload = {
    contents: [{
      role: "user",
      parts: [
        { text: prompt },
        { inlineData: { mimeType: "image/png", data: image } }
      ]
    }]
  };

  try {
    const response = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-09-2025:generateContent?key=${apiKey}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    const result = await response.json();
    const text = result.candidates?.[0]?.content?.parts?.[0]?.text || "鑑定結果が得られませんでした。";
    
    res.status(200).json({ text });
  } catch (err) {
    res.status(500).json({ error: 'Internal Server Error' });
  }
}